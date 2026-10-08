#!/bin/bash
# =============================================================================
# Installa il servizio ML di snippet (embedding + trascrizione) in
# /opt/snippet-ml come servizio systemd. Idempotente.
#
#   sudo bash ml/deploy/install-ml.sh
#
# Senza variabili scarica tutto da Internet (pip da PyPI, modelli da
# HuggingFace, sorgente di whisper.cpp da GitHub, compilato in locale).
# Per riusare materiale gia' presente sulla macchina:
#   VENV_FROM=/percorso/venv         virtualenv con onnxruntime/numpy/tokenizers
#   MODELS_FROM=/percorso/models     contiene e5-small/ e ggml-*.bin
#   WHISPER_FROM=/percorso/whisper-cli   binario gia' compilato (statico)
#   WHISPER_MODEL=ggml-large-v3-turbo-q5_0.bin   (default)
# =============================================================================
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"          # .../ml
OPT="/opt/snippet-ml"
SVCUSER="snippet-ml"
WHISPER_MODEL="${WHISPER_MODEL:-ggml-large-v3-turbo-q5_0.bin}"
WHISPER_TAG="${WHISPER_TAG:-v1.9.5}"

[ "$(id -u)" -eq 0 ] || { echo "Esegui con sudo."; exit 1; }
[ -f "$SRC/snippet_ml.py" ] || { echo "sorgente non trovata: $SRC"; exit 1; }
command -v ffmpeg >/dev/null || { echo "manca ffmpeg (apt install ffmpeg)"; exit 1; }

echo "== 1. Utente di servizio =="
id "$SVCUSER" >/dev/null 2>&1 || useradd -r -s /usr/sbin/nologin -d "$OPT" "$SVCUSER"
install -d -o "$SVCUSER" -g "$SVCUSER" -m 0750 "$OPT" "$OPT/bin" "$OPT/models" "$OPT/models/e5-small"
install -o "$SVCUSER" -g "$SVCUSER" -m 0644 "$SRC/snippet_ml.py" "$SRC/requirements.txt" "$OPT/"

echo "== 2. .env =="
if [ ! -f "$OPT/.env" ]; then
  sed -e "s#^ML_TOKEN=.*#ML_TOKEN=$(php -r 'echo bin2hex(random_bytes(24));')#" \
      -e "s#^ML_WHISPER_MODEL=.*#ML_WHISPER_MODEL=$OPT/models/$WHISPER_MODEL#" \
      "$SRC/.env.example" > "$OPT/.env"
  echo "   creato $OPT/.env (token generato)"
else
  echo "   $OPT/.env gia' presente: lasciato invariato"
fi
chown "$SVCUSER:$SVCUSER" "$OPT/.env"; chmod 0600 "$OPT/.env"

echo "== 3. venv =="
if [ -n "${VENV_FROM:-}" ] && [ -x "$VENV_FROM/bin/python" ]; then
  rm -rf "$OPT/venv"; cp -a "$VENV_FROM" "$OPT/venv"; echo "   copiato da $VENV_FROM"
elif [ ! -x "$OPT/venv/bin/python" ]; then
  python3 -m venv "$OPT/venv"
  "$OPT/venv/bin/pip" -q install --upgrade pip
  "$OPT/venv/bin/pip" -q install -r "$OPT/requirements.txt"
fi
"$OPT/venv/bin/python" -c "import onnxruntime, numpy, tokenizers" && echo "   dipendenze ok"

echo "== 4. Modelli =="
fetch() {  # fetch <url> <dest>
  [ -s "$2" ] && return 0
  curl -fL --retry 3 -o "$2.part" "$1" && mv "$2.part" "$2"
}
if [ -n "${MODELS_FROM:-}" ]; then
  cp -n "$MODELS_FROM/e5-small/"* "$OPT/models/e5-small/" 2>/dev/null || true
  [ -f "$MODELS_FROM/$WHISPER_MODEL" ] && cp -n "$MODELS_FROM/$WHISPER_MODEL" "$OPT/models/" || true
fi
fetch https://huggingface.co/Xenova/multilingual-e5-small/resolve/main/onnx/model_quantized.onnx "$OPT/models/e5-small/model_quantized.onnx"
fetch https://huggingface.co/Xenova/multilingual-e5-small/resolve/main/tokenizer.json "$OPT/models/e5-small/tokenizer.json"
fetch "https://huggingface.co/ggerganov/whisper.cpp/resolve/main/$WHISPER_MODEL" "$OPT/models/$WHISPER_MODEL"

echo "== 5. whisper.cpp =="
if [ -n "${WHISPER_FROM:-}" ] && [ -x "$WHISPER_FROM" ]; then
  install -m 0755 "$WHISPER_FROM" "$OPT/bin/whisper-cli"; echo "   copiato da $WHISPER_FROM"
elif [ ! -x "$OPT/bin/whisper-cli" ]; then
  B="$(mktemp -d)"
  git clone -q --depth 1 --branch "$WHISPER_TAG" https://github.com/ggml-org/whisper.cpp "$B/w"
  cmake -S "$B/w" -B "$B/w/build" -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF \
        -DWHISPER_BUILD_TESTS=OFF -DWHISPER_BUILD_SERVER=OFF >/dev/null
  cmake --build "$B/w/build" -j "$(nproc)" --target whisper-cli >/dev/null
  install -m 0755 "$B/w/build/bin/whisper-cli" "$OPT/bin/whisper-cli"
  rm -rf "$B"
fi
chown -R "$SVCUSER:$SVCUSER" "$OPT"

echo "== 6. systemd =="
install -m 0644 "$SRC/deploy/snippet-ml.service.sample" /etc/systemd/system/snippet-ml.service
systemctl daemon-reload
systemctl enable snippet-ml.service >/dev/null
systemctl restart snippet-ml.service
for i in $(seq 1 30); do
  curl -s -m 2 -H "Authorization: Bearer $(grep ^ML_TOKEN= "$OPT/.env" | cut -d= -f2)" \
       http://127.0.0.1:8765/health 2>/dev/null | grep -q '"ok": true' && break
  sleep 1
done
curl -s -m 2 -H "Authorization: Bearer $(grep ^ML_TOKEN= "$OPT/.env" | cut -d= -f2)" http://127.0.0.1:8765/health; echo
echo
echo "FATTO. Collega webapp e bot al servizio:"
echo "  config.php:  'ml_url' => 'http://127.0.0.1:8765', 'ml_token' => '<ML_TOKEN di $OPT/.env>'"
echo "  bot .env:    ML_URL=http://127.0.0.1:8765  ML_TOKEN=<lo stesso>"
