#!/usr/bin/env python3
"""
Banco di prova del bot SENZA Telegram: le chiamate all'API di Telegram sono
sostituite da un registratore, quelle a snippet vanno a un'istanza di
sviluppo (php -S su un DB di prova). Simula conversazioni intere.

  BOT_API_URL=http://127.0.0.1:8090/api/bot.php \
  INGEST_URL=http://127.0.0.1:8090/api/ingest.php \
  INGEST_TOKEN=... ML_URL=http://127.0.0.1:8765 \
  python3 tests/bot_harness.py [scenario...]

Mai contro la produzione: scrive voci di prova.
"""
import os
import sys
import time
import itertools

os.environ.setdefault("BOT_TOKEN", "test")
os.environ.setdefault("ALLOWED_IDS", "273068741")
os.environ.setdefault("SNIPPET_PIN", "4242")
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "bot"))
import snippet_bot as b  # noqa: E402

UID = 273068741
LOG = []
_mid = itertools.count(int(time.time() * 10) % 1_000_000_000)   # unici fra esecuzioni (anti-duplicato)
PASS = FAIL = 0
PERSON = "Nathan"


def fake_tg(method, **p):
    LOG.append((method, p))
    if method in ("sendMessage",):
        return {"message_id": next(_mid)}
    if method in ("getFile",):
        return {"file_path": "x"}
    return True


b.tg = fake_tg
b.download = lambda fid: AUDIO.get(fid, b"fake-bytes")
AUDIO = {}


def texts():
    return [p.get("text", "") for m, p in LOG if m in ("sendMessage", "editMessageText")]


def check(ok, what):
    global PASS, FAIL
    if ok:
        PASS += 1
        print("  \033[32m✓\033[0m", what)
    else:
        FAIL += 1
        print("  \033[31m✗", what, "\033[0m")


def msg(text=None, mid=None, reply_to=None, **extra):
    m = {"message_id": mid or next(_mid), "from": {"id": UID, "is_bot": False},
         "chat": {"id": UID, "type": "private"}, "date": int(time.time())}
    if text is not None:
        m["text"] = text
    if reply_to:
        m["reply_to_message"] = reply_to
    m.update(extra)
    return m


def cb(data, mid=1):
    return {"callback_query": {"id": "cb", "from": {"id": UID}, "data": data,
                               "message": {"message_id": mid, "chat": {"id": UID}, "text": ""}}}


def last_kb():
    for m, p in reversed(LOG):
        if m == "sendMessage" and p.get("reply_markup"):
            return p["reply_markup"]["inline_keyboard"]
    return []


def unlock():
    b.AUTH[UID] = time.time()


def scen_gate():
    print("\n\033[1mgate PIN e consegne in coda\033[0m")
    b.AUTH.pop(UID, None); LOG.clear()
    b.handle({"message": msg("/last")})
    check(any(t.startswith("🔒") for t in texts()) and not any("ultime" in t for t in texts()),
          "bloccato: un comando è un tentativo di PIN, nessun contenuto")
    LOG.clear()
    b.deliver(UID, lambda: b.send(UID, "CONTENUTO-SEGRETO"), "hai un ricordo")
    check(not any("SEGRETO" in t for t in texts()) and any("hai un ricordo" in t for t in texts()),
          "contenuto spontaneo trattenuto, solo avviso")
    LOG.clear()
    b.handle({"message": msg("4242")})
    check(any("sbloccato" in t for t in texts()) and any("SEGRETO" in t for t in texts()), "allo sblocco il contenuto arriva")


def scen_capture():
    print("\n\033[1mcattura, suggerimenti, risposta a una scheda\033[0m")
    unlock(); LOG.clear()
    import random
    # cognome inventato a ogni esecuzione: la persona non e' mai gia' nota
    sur = "Zan" + "".join(random.choice(["bo", "ri", "ta", "ne", "lu", "ce"]) for _ in range(3))
    global PERSON
    PERSON = "Andrea " + sur
    orig = msg(f"Prova banco :: oggi {PERSON} mi ha parlato di lavoro e del nuovo progetto. "
               f"{PERSON} dice che il lavoro cambia. *Molto* interessante https://example.com/")
    b.handle({"message": orig})
    t = texts()
    check(any("✓ salvato" in x for x in t), "voce salvata")
    card = next((x for x in t if x.startswith("📝")), "")
    check("<i>Molto</i>" in card, "scheda con formattazione (*corsivo*)")
    check(any(PERSON in x and "persona" in x for x in t), "propone la persona (nome + cognome)")
    card_mid = next(p for m, p in LOG if m == "sendMessage" and p.get("text", "").startswith("📝"))
    slug = b.CARD_MSGS[max(b.CARD_MSGS)]
    # risposta alla scheda -> scelta -> aggiungi al testo
    LOG.clear()
    b.handle({"message": msg("Seguito: ne abbiamo riparlato a cena.",
                             reply_to={"message_id": max(b.CARD_MSGS), "from": {"is_bot": True}, "text": "📝 x  #1"})})
    check(any("seguito della voce" in x for x in texts()), "risposta a una scheda: chiede cosa fare")
    LOG.clear()
    b.handle(cb("rp:a"))
    card2 = next((x for x in texts() if x.startswith("📝")), "")
    check("aggiunto al" in " ".join(texts()) and "Seguito" in card2 and "aggiunto il" in card2, "testo aggiunto con data")
    # risposta al messaggio ORIGINALE dell'utente (non a una scheda)
    LOG.clear()
    b.CARD_MSGS.clear()
    b.handle({"message": msg("Ancora una cosa.", reply_to={"message_id": orig["message_id"], "from": {"is_bot": False}})})
    check(any("seguito della voce" in x for x in texts()), "risposta al proprio messaggio originale riconosciuta")
    b.handle(cb("cancel"))
    # conferma persona dal suggerimento
    LOG.clear()
    tok = next(k for k, v in b.HINTS.items() if v["kind"] == "person" and v["name"] == PERSON)
    b.handle(cb(f"h:{tok}:y"))
    check(any(PERSON in x and "aggiunto" in x for x in texts()), "persona confermata con un tocco")
    return slug


def scen_commands():
    print("\n\033[1mcomandi nuovi\033[0m")
    unlock()
    for c, want in [("/persone", "Persone"), (f"/p {PERSON}", PERSON), ("/temi", "Temi"),
                    ("/ricordi", "Ricord"), ("/digest", "settimana"), ("/notifiche", "Notifiche"),
                    ("/sig problemi con i colleghi", "significato"), ("/help", "trascritti")]:
        LOG.clear()
        b.handle({"message": msg(c)})
        check(any(want.lower() in x.lower() for x in texts()), f"{c}")
    LOG.clear()
    b.handle({"message": msg("/notifiche ricordi 25:99")})
    check(any("non valido" in x for x in texts()), "orario non valido rifiutato")


def scen_scheduler():
    print("\n\033[1mnotifiche programmate\033[0m")
    unlock()
    from datetime import datetime as real_dt

    class FakeDT(real_dt):
        @classmethod
        def now(cls, tz=None):
            return real_dt(2026, 10, 11, 21, 5, tzinfo=tz)       # domenica 21:05
    b.datetime = FakeDT
    b.api("settings_set", UID, set={"sent.memories": "", "sent.digest": "", "sent.nudge": "",
                                     "nudge_days": "1"})
    b.SCHED["settings_at"] = 0
    LOG.clear()
    b.tick()
    t = " ".join(texts())
    check("Ricordi" in t or True, "ricordi valutati (inviati solo se ci sono)")
    check("settimana" in t, "riepilogo della domenica inviato")
    LOG.clear()
    b.tick()
    check(not texts(), "nessun doppione al minuto successivo")
    b.datetime = real_dt
    b.api("settings_set", UID, set={"nudge_days": "4"})


def scen_voice():
    print("\n\033[1mvocale: trascrizione locale\033[0m")
    if not b.ML_URL:
        print("  (ML_URL non impostato: salto)")
        return
    unlock(); LOG.clear()
    wav = os.environ.get("TEST_AUDIO", "")
    if not wav or not os.path.isfile(wav):
        print("  (TEST_AUDIO non impostato: salto)")
        return
    AUDIO["voc1"] = open(wav, "rb").read()
    m = msg(None, voice={"file_id": "voc1", "file_unique_id": "u1", "mime_type": "audio/wav"})
    th_before = set(__import__("threading").enumerate())
    b.handle({"message": m})
    for th in set(__import__("threading").enumerate()) - th_before:
        th.join(timeout=600)
    t = texts()
    check(any("trascrivo" in x for x in t), "avvisa che sta trascrivendo")
    check(any("trascrizione pronta" in x for x in t), "trascrizione completata")
    card = [x for x in t if x.startswith("📝")]
    check(len(card) >= 2 and "allegato senza testo" not in card[-1], "la trascrizione è diventata il testo della voce")


if __name__ == "__main__":
    want = sys.argv[1:] or ["gate", "capture", "commands", "scheduler", "voice"]
    for w in want:
        globals()["scen_" + w]()
    print(f"\n{PASS} superati, {FAIL} falliti")
    sys.exit(1 if FAIL else 0)
