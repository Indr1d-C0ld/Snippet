#!/usr/bin/env python3
"""
snippet-ml - servizio locale di machine learning per snippet.

Due funzioni, entrambe eseguite interamente su questo server (nessun dato
esce verso servizi esterni):

  POST /embed       {"texts": [...], "kind": "passage"|"query"}
                    -> {"ok": true, "vectors": [[384 float], ...], "model": ...}
                    Vettori semantici normalizzati (multilingual-e5-small,
                    ONNX quantizzato). Usati da snippet per la ricerca "per
                    significato", le voci simili e le correlazioni.

  POST /transcribe  corpo = file audio (qualunque formato letto da ffmpeg)
                    -> {"ok": true, "text": "...", "seconds": durata audio,
                        "elapsed": tempo impiegato}
                    Trascrizione con whisper.cpp (lingua configurabile). Una
                    trascrizione alla volta, con priorita' minima (nice 19)
                    per non rallentare il resto del server.

  GET  /health      -> {"ok": true, "embed": bool, "whisper": bool}

Ascolta solo su 127.0.0.1. Se ML_TOKEN e' impostato, ogni richiesta deve
portare "Authorization: Bearer <ML_TOKEN>".

Configurazione da ambiente (vedi ml/.env.example).
"""

import os
import io
import json
import time
import wave
import logging
import tempfile
import threading
import subprocess
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler

import numpy as np

HERE = os.path.dirname(os.path.abspath(__file__))
HOST = os.environ.get("ML_HOST", "127.0.0.1")
PORT = int(os.environ.get("ML_PORT", "8765"))
TOKEN = os.environ.get("ML_TOKEN", "")
EMBED_DIR = os.environ.get("ML_EMBED_DIR", os.path.join(HERE, "models", "e5-small"))
EMBED_NAME = os.environ.get("ML_EMBED_NAME", "multilingual-e5-small")
WHISPER_BIN = os.environ.get("ML_WHISPER_BIN", os.path.join(HERE, "bin", "whisper-cli"))
WHISPER_MODEL = os.environ.get("ML_WHISPER_MODEL", os.path.join(HERE, "models", "ggml-small.bin"))
WHISPER_LANG = os.environ.get("ML_WHISPER_LANG", "it")
WHISPER_THREADS = int(os.environ.get("ML_WHISPER_THREADS", "4"))   # misurato: con 8 thread su 4 core carichi e' 3x piu' lento
FFMPEG = os.environ.get("ML_FFMPEG", "ffmpeg")
MAX_AUDIO_BYTES = int(os.environ.get("ML_MAX_AUDIO_BYTES", str(25 * 1024 * 1024)))
MAX_AUDIO_SECONDS = int(os.environ.get("ML_MAX_AUDIO_SECONDS", "900"))
MAX_TEXTS = 64

logging.basicConfig(format="%(asctime)s %(levelname)s %(message)s", level=logging.INFO)
log = logging.getLogger("snippet-ml")


# ----------------------------------------------------------------- embedding

class Embedder:
    """multilingual-e5-small: mean pooling + normalizzazione L2. Il modello
    distingue i ruoli col prefisso: "query: " per le ricerche, "passage: "
    per i testi indicizzati."""

    def __init__(self, path):
        import onnxruntime as ort
        from tokenizers import Tokenizer
        opts = ort.SessionOptions()
        opts.intra_op_num_threads = max(1, min(4, os.cpu_count() or 1))
        self.sess = ort.InferenceSession(os.path.join(path, "model_quantized.onnx"),
                                         sess_options=opts, providers=["CPUExecutionProvider"])
        self.tok = Tokenizer.from_file(os.path.join(path, "tokenizer.json"))
        self.tok.enable_truncation(max_length=512)
        self.tok.enable_padding(pad_id=self.tok.token_to_id("<pad>") or 1, pad_token="<pad>")
        self.inputs = {i.name for i in self.sess.get_inputs()}
        self.lock = threading.Lock()

    def embed(self, texts, kind):
        prefix = "query: " if kind == "query" else "passage: "
        enc = self.tok.encode_batch([prefix + (t or "") for t in texts])
        ids = np.array([e.ids for e in enc], dtype=np.int64)
        mask = np.array([e.attention_mask for e in enc], dtype=np.int64)
        feed = {"input_ids": ids, "attention_mask": mask}
        if "token_type_ids" in self.inputs:
            feed["token_type_ids"] = np.zeros_like(ids)
        with self.lock:
            out = self.sess.run(None, feed)[0]                     # (b, seq, 384)
        m = mask[..., None].astype(np.float32)
        vec = (out * m).sum(axis=1) / np.clip(m.sum(axis=1), 1e-9, None)
        vec /= np.clip(np.linalg.norm(vec, axis=1, keepdims=True), 1e-12, None)
        return vec.astype(np.float32)


_EMB = None
_EMB_ERR = None


def embedder():
    global _EMB, _EMB_ERR
    if _EMB is None and _EMB_ERR is None:
        try:
            t = time.time()
            _EMB = Embedder(EMBED_DIR)
            log.info("modello embedding caricato in %.1fs (%s)", time.time() - t, EMBED_DIR)
        except Exception as e:  # noqa: BLE001
            _EMB_ERR = str(e)
            log.error("embedding non disponibile: %s", e)
    return _EMB


# ------------------------------------------------------------- trascrizione

_WHISPER_LOCK = threading.Lock()


def whisper_ok():
    return os.path.isfile(WHISPER_BIN) and os.access(WHISPER_BIN, os.X_OK) and os.path.isfile(WHISPER_MODEL)


def transcribe(blob):
    if not whisper_ok():
        raise RuntimeError("whisper non installato")
    with tempfile.TemporaryDirectory(prefix="snippet-ml-") as tmp:
        src = os.path.join(tmp, "in.bin")
        wav = os.path.join(tmp, "audio.wav")
        with open(src, "wb") as f:
            f.write(blob)
        # qualunque formato -> WAV 16 kHz mono, come vuole whisper
        r = subprocess.run([FFMPEG, "-nostdin", "-loglevel", "error", "-y", "-i", src,
                            "-ar", "16000", "-ac", "1", "-c:a", "pcm_s16le", wav],
                           capture_output=True, timeout=120)
        if r.returncode != 0 or not os.path.isfile(wav):
            raise RuntimeError("audio non leggibile: " + r.stderr.decode(errors="replace")[-200:])
        with wave.open(wav, "rb") as w:
            seconds = w.getnframes() / float(w.getframerate() or 16000)
        if seconds > MAX_AUDIO_SECONDS:
            raise RuntimeError(f"audio troppo lungo ({int(seconds)} s, massimo {MAX_AUDIO_SECONDS})")
        with _WHISPER_LOCK:                                    # una alla volta
            t = time.time()
            r = subprocess.run(["nice", "-n", "19", WHISPER_BIN, "-m", WHISPER_MODEL, "-f", wav,
                                "-l", WHISPER_LANG, "-t", str(WHISPER_THREADS), "-np", "-nt"],
                               capture_output=True, timeout=int(60 + seconds * 20))
            elapsed = time.time() - t
        if r.returncode != 0:
            raise RuntimeError("whisper: " + r.stderr.decode(errors="replace")[-300:])
        lines = [ln.strip() for ln in r.stdout.decode(errors="replace").splitlines()]
        text = " ".join(ln for ln in lines if ln and not (ln.startswith("[") and ln.endswith("]")))
        return {"text": text.strip(), "seconds": round(seconds, 1), "elapsed": round(elapsed, 1)}


# --------------------------------------------------------------------- HTTP

class Handler(BaseHTTPRequestHandler):
    server_version = "snippet-ml/1"

    def log_message(self, fmt, *args):     # niente log di accesso con dati
        pass

    def _send(self, code, obj):
        body = json.dumps(obj, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _auth(self):
        if not TOKEN:
            return True
        h = self.headers.get("Authorization", "")
        import hmac
        if h.startswith("Bearer ") and hmac.compare_digest(h[7:].strip(), TOKEN):
            return True
        self._send(401, {"ok": False, "error": "token non valido"})
        return False

    def _body(self, limit):
        n = int(self.headers.get("Content-Length") or 0)
        if n <= 0 or n > limit:
            self._send(413 if n > limit else 400, {"ok": False, "error": "corpo assente o troppo grande"})
            return None
        return self.rfile.read(n)

    def do_GET(self):
        if self.path != "/health":
            return self._send(404, {"ok": False, "error": "non trovato"})
        if not self._auth():
            return
        self._send(200, {"ok": True, "embed": embedder() is not None, "embed_model": EMBED_NAME,
                         "whisper": whisper_ok(), "whisper_model": os.path.basename(WHISPER_MODEL)})

    def do_POST(self):
        if not self._auth():
            return
        if self.path == "/embed":
            raw = self._body(4 * 1024 * 1024)
            if raw is None:
                return
            try:
                d = json.loads(raw)
                texts = [str(t) for t in d.get("texts", [])][:MAX_TEXTS]
                kind = "query" if d.get("kind") == "query" else "passage"
            except Exception:  # noqa: BLE001
                return self._send(400, {"ok": False, "error": "JSON non valido"})
            emb = embedder()
            if emb is None:
                return self._send(503, {"ok": False, "error": "embedding non disponibile: " + str(_EMB_ERR)})
            t = time.time()
            vec = emb.embed(texts, kind) if texts else np.zeros((0, 384), np.float32)
            return self._send(200, {"ok": True, "model": EMBED_NAME, "dim": int(vec.shape[1]),
                                    "elapsed": round(time.time() - t, 3),
                                    "vectors": [[round(float(x), 6) for x in v] for v in vec]})
        if self.path == "/transcribe":
            raw = self._body(MAX_AUDIO_BYTES)
            if raw is None:
                return
            try:
                res = transcribe(raw)
            except subprocess.TimeoutExpired:
                return self._send(504, {"ok": False, "error": "trascrizione troppo lenta (timeout)"})
            except Exception as e:  # noqa: BLE001
                return self._send(422, {"ok": False, "error": str(e)})
            log.info("trascritti %.0fs di audio in %.0fs", res["seconds"], res["elapsed"])
            return self._send(200, {"ok": True, **res})
        self._send(404, {"ok": False, "error": "non trovato"})


def main():
    embedder()                                   # carica subito: la prima voce non aspetta
    log.info("whisper: %s", ("ok, " + os.path.basename(WHISPER_MODEL)) if whisper_ok() else "non installato")
    srv = ThreadingHTTPServer((HOST, PORT), Handler)
    srv.daemon_threads = True
    log.info("snippet-ml in ascolto su http://%s:%d", HOST, PORT)
    srv.serve_forever()


if __name__ == "__main__":
    main()
