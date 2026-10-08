#!/usr/bin/env python3
"""
snippet - bot Telegram: interfaccia completa alla piattaforma.

Cattura (qualunque messaggio -> nuova voce, con allegati; i vocali vengono
trascritti in locale) e navigazione/modifica dell'intero archivio: /last
/search /sig /tag /tags /day /random /stats /persone /temi /ricordi /digest,
apertura voce con keyboard inline (correlati, simili, persone, tema, pin,
archivia, modifica, nota, tag, elimina, apri nel web).

Dal 08/10/2026 il bot cerca anche te: ricordi ("un mese fa scrivevi..."),
riepilogo settimanale e un promemoria dopo qualche giorno di silenzio
(/notifiche per regolarli). Rispondere a una scheda aggiunge testo alla voce.

Nessuna dipendenza pesante: long-polling grezzo su `requests`. Tutta la logica
di dati/NLP resta in PHP dietro `api/bot.php` e `api/ingest.php`; la
trascrizione la fa il servizio locale snippet-ml (ml/snippet_ml.py).

Config da ambiente (vedi .env.example):
  BOT_TOKEN       token di @BotFather
  INGEST_TOKEN    = cfg()['ingest_token'] della webapp
  BOT_API_URL     .../snippet/api/bot.php
  INGEST_URL      .../snippet/api/ingest.php
  PUBLIC_BASE_URL https://host/snippet   (link "apri nel web")
  ALLOWED_IDS     lista di ID Telegram ammessi (csv); vuoto = solo il gate lato server
  SNIPPET_PIN / SNIPPET_PIN_TIMEOUT   gate PIN (facoltativo)
  ML_URL / ML_TOKEN                   servizio snippet-ml (facoltativo)
  SNIPPET_TZ      fuso per le notifiche programmate (default Europe/Rome)
"""

import os
import re
import sys
import html
import time
import json
import logging
import threading
import itertools
from datetime import datetime
from zoneinfo import ZoneInfo

import requests

BOT_TOKEN = os.environ.get("BOT_TOKEN", "")
INGEST_TOKEN = os.environ.get("INGEST_TOKEN", "")
BOT_API_URL = os.environ.get("BOT_API_URL", "http://127.0.0.1/snippet/api/bot.php")
INGEST_URL = os.environ.get("INGEST_URL", "http://127.0.0.1/snippet/api/ingest.php")
PUBLIC_BASE_URL = os.environ.get("PUBLIC_BASE_URL", "").rstrip("/")
ALLOWED_IDS = {int(x) for x in os.environ.get("ALLOWED_IDS", "").replace(" ", "").split(",") if x}
PIN = os.environ.get("SNIPPET_PIN", "").strip()
PIN_TTL = int(os.environ.get("SNIPPET_PIN_TIMEOUT", "1800") or "1800")  # validità sblocco (s)
ML_URL = os.environ.get("ML_URL", "").rstrip("/")
ML_TOKEN = os.environ.get("ML_TOKEN", "")
TZ = ZoneInfo(os.environ.get("SNIPPET_TZ", "Europe/Rome"))

if not BOT_TOKEN or not INGEST_TOKEN:
    print("BOT_TOKEN / INGEST_TOKEN mancanti (vedi .env.example)", file=sys.stderr)
    sys.exit(1)

TG = "https://api.telegram.org/bot" + BOT_TOKEN
HTTP_T, UPLOAD_T, POLL_T = 30, 120, 25
TRANSCRIBE_T = 1800          # un vocale lungo su CPU lenta puo' richiedere minuti

logging.basicConfig(format="%(asctime)s %(levelname)s %(message)s", level=logging.INFO)
log = logging.getLogger("snippet-bot")

STATE: dict = {}        # chat_id -> {"await": str, ...}
CARD_MSGS: dict = {}    # message_id -> slug  (schede di una voce: rispondere = aggiungere)
CONTENT_MSGS: set = set()   # altri messaggi con contenuto del diario (liste, digest...): /lock li cancella
AUTH: dict = {}         # chat_id -> epoch dello sblocco (finestra fissa PIN_TTL)
PIN_FAIL: dict = {}     # chat_id -> [tentativi_falliti, blocco_fino_a_epoch]
QUEUE: dict = {}        # chat_id -> [callable]: contenuti in attesa dello sblocco
HINTS: dict = {}        # token -> dict: bottoni dei suggerimenti (persone/tag)
_TOK = itertools.count(1)

_CANCEL_KB = [[{"text": "✕ annulla", "callback_data": "cancel"}]]


# --------------------------------------------------------------------------- PIN

def gate_ok(chat_id):
    """True se il gate è disattivato o la chat è sbloccata e non scaduta.
    La finestra è FISSA: PIN_TTL secondi dallo sblocco, poi serve di nuovo il PIN
    (a prescindere dall'attività). Anche ogni riavvio del servizio ri-blocca,
    perché AUTH sta solo in memoria."""
    if not PIN:
        return True
    return (time.time() - AUTH.get(chat_id, 0)) < PIN_TTL


def gate_try(chat_id, text):
    """Valuta un tentativo di PIN. Ritorna (esito, extra):
       ok / bad (extra = tentativi rimasti) / locked (extra = secondi di blocco)."""
    now = time.time()
    cnt, until = PIN_FAIL.get(chat_id, [0, 0])
    if now < until:
        return "locked", int(until - now)
    if text.strip() == PIN:
        AUTH[chat_id] = now
        PIN_FAIL.pop(chat_id, None)
        return "ok", 0
    cnt += 1
    if cnt >= 5:
        PIN_FAIL[chat_id] = [0, now + 300]      # 5 tentativi -> 5 min di blocco
        return "locked", 300
    PIN_FAIL[chat_id] = [cnt, 0]
    return "bad", 5 - cnt


def deliver(chat_id, fn, notice):
    """Consegna un contenuto del diario che arriva "da solo" (trascrizione
    finita, ricordo, digest). Se il bot è bloccato dal PIN il contenuto NON
    va in chat: si manda solo un avviso senza dati e lo si consegna allo
    sblocco."""
    if gate_ok(chat_id):
        fn()
        return
    q = QUEUE.setdefault(chat_id, [])
    q.append(fn)
    if len(q) == 1 or notice:
        send(chat_id, "🔒 " + notice + " — inviami il PIN per vederlo.")


def flush_queue(chat_id):
    for fn in QUEUE.pop(chat_id, []):
        try:
            fn()
        except Exception:  # noqa: BLE001
            log.exception("consegna in coda")


# Registrati su Telegram: appaiono nel menu "/" e nell'autocompletamento.
BOT_COMMANDS = [
    ("last", "ultime voci  [/last 20]"),
    ("search", "cerca parole  [/search lavoro tag:jamal da:01/09/2026]"),
    ("sig", "cerca per significato  [/sig litigi in ufficio]"),
    ("tag", "voci con un tag  [/tag viaggio]"),
    ("tags", "i tag più usati"),
    ("persone", "le persone del diario  [/p Jamal]"),
    ("temi", "i temi ricorrenti"),
    ("day", "voci di un giorno  [/day 04/09/2026]"),
    ("today", "voci di oggi"),
    ("random", "una voce a caso"),
    ("e", "apri una voce: id, slug o data  [/e 7 • /e 04/09/2026]"),
    ("ricordi", "cosa scrivevi in questi giorni, mesi e anni fa"),
    ("digest", "riepilogo degli ultimi 7 giorni"),
    ("stats", "numeri e streak"),
    ("saved", "ricerche salvate"),
    ("notifiche", "ricordi, digest e promemoria"),
    ("rebuild", "ricalcola le correlazioni"),
    ("lock", "blocca il bot (richiede il PIN)"),
    ("help", "guida"),
]

HELP = (
    "<b>snippet</b> — diario con correlazione automatica\n\n"
    "Scrivi un messaggio qualsiasi → diventa una voce. Foto, documenti e note "
    "vocali vengono allegati; <b>i vocali vengono trascritti</b> e il testo "
    "entra nella voce.\n\n"
    "<b>Titolo</b>: <code>Titolo :: resto del pensiero</code> sulla prima riga, "
    "oppure prima riga breve e poi una riga vuota.\n"
    "<b>Direttive</b>: <code>#tag</code> · <code>[[123]]</code> collega · "
    "<code>!data:01/09/2026</code> · <code>!nolink</code> · <code>!pin</code> · "
    "<code>!tag:studio, viaggio</code>\n"
    "<b>Formattazione</b>: <code>*corsivo*</code> · <code>**grassetto**</code> · "
    "righe che iniziano con <code>- </code> diventano elenchi\n\n"
    "<b>Rispondi a una scheda</b> (o al tuo messaggio originale) per aggiungere "
    "testo a quella voce, o una nota.\n\n"
    "<b>Comandi</b>\n"
    "/last [n] — ultime voci\n"
    "/search &lt;testo&gt; — ricerca per parole (anche /s). Filtri: "
    "<code>tag:x</code> <code>persona:x</code> <code>da:GG/MM/AAAA</code> "
    "<code>a:GG/MM/AAAA</code> <code>tema:n</code>\n"
    "/sig &lt;testo&gt; — ricerca per significato\n"
    "/tag &lt;nome&gt; · /tags — tag\n"
    "/persone · /p &lt;nome&gt; — persone e loro cronologia\n"
    "/temi — temi ricorrenti\n"
    "/day [GG/MM/AAAA] · /today · /random (/r)\n"
    "/e &lt;slug|id&gt; — apri una voce\n"
    "/ricordi · /digest — il passato e la settimana\n"
    "/stats · /saved · /notifiche\n"
    "/rebuild — ricalcola le correlazioni\n"
    "/lock — blocca il bot (poi serve il PIN)"
)


# --------------------------------------------------------------------------- IO

def tg(method, **params):
    files = params.pop("_files", None)
    # per il long-poll (getUpdates) il read-timeout deve superare `timeout`
    lp = params.get("timeout", 0)
    read_to = (lp + 10) if lp else HTTP_T
    try:
        if files:
            r = requests.post(f"{TG}/{method}", data=params, files=files, timeout=UPLOAD_T)
        else:
            r = requests.post(f"{TG}/{method}", json=params, timeout=read_to)
        d = r.json()
    except Exception as e:  # noqa: BLE001
        log.warning("tg %s: %s", method, e)
        return None
    if not d.get("ok"):
        log.warning("tg %s -> %s", method, d.get("description"))
        return None
    return d.get("result")


def api(cmd, from_id, **kw):
    timeout = kw.pop("_timeout", HTTP_T)       # non va nel JSON
    body = {"cmd": cmd, "from_id": from_id, **kw}
    try:
        r = requests.post(BOT_API_URL, json=body,
                          headers={"Authorization": "Bearer " + INGEST_TOKEN},
                          timeout=timeout)
        return r.json()
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "error": str(e)}


def send(chat_id, text, kb=None, preview=False, reply_to=None, content=False):
    p = {"chat_id": chat_id, "text": text, "parse_mode": "HTML",
         "disable_web_page_preview": not preview}
    if kb is not None:
        p["reply_markup"] = {"inline_keyboard": kb}
    if reply_to:
        p["reply_to_message_id"] = reply_to
    res = tg("sendMessage", **p)
    if res and content:
        CONTENT_MSGS.add((chat_id, res["message_id"]))
    return res


def edit(chat_id, message_id, text, kb=None):
    p = {"chat_id": chat_id, "message_id": message_id, "text": text,
         "parse_mode": "HTML", "disable_web_page_preview": True}
    if kb is not None:
        p["reply_markup"] = {"inline_keyboard": kb}
    return tg("editMessageText", **p)


def answer_cb(cb_id, text=None):
    p = {"callback_query_id": cb_id}
    if text:
        p["text"] = text
    tg("answerCallbackQuery", **p)


def bg(fn, *args):
    """Esegue fn in un thread: lavori lenti (trascrizioni, anteprime) senza
    bloccare la ricezione degli altri messaggi."""
    def run():
        try:
            fn(*args)
        except Exception:  # noqa: BLE001
            log.exception("lavoro in background %s", getattr(fn, "__name__", fn))
    threading.Thread(target=run, daemon=True).start()


# ---------------------------------------------------------------- formattazione

def esc(s):
    return html.escape(str(s or ""))


def md(s):
    """Testo della voce -> HTML di Telegram: escape, poi **grassetto**,
    *corsivo* / _corsivo_ (stesse regole leggere della webapp)."""
    t = esc(s)
    t = re.sub(r"\*\*(\S(?:.*?\S)?)\*\*", r"<b>\1</b>", t)
    t = re.sub(r"(?<![\w*])\*(\S(?:[^*\n]*?\S)?)\*(?![\w*])", r"<i>\1</i>", t)
    t = re.sub(r"(?<![\w_])_(\S(?:[^_\n]*?\S)?)_(?![\w_])", r"<i>\1</i>", t)
    return t


def to_iso_date(s):
    """GG/MM/AAAA (o GG-MM-AAAA, GG.MM.AAAA) -> AAAA-MM-GG. Passa oltre l'ISO. '' se non e' una data."""
    s = (s or "").strip()
    m = re.match(r"^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$", s)
    if m:
        d, mo, y = m.groups()
        return f"{y}-{int(mo):02d}-{int(d):02d}"
    if re.match(r"^\d{4}-\d{2}-\d{2}$", s):
        return s
    return ""


def from_iso_date(s):
    """AAAA-MM-GG -> GG/MM/AAAA (per la visualizzazione)."""
    p = (s or "").split("-")
    return f"{p[2]}/{p[1]}/{p[0]}" if len(p) == 3 else (s or "")


def it_slug(s):
    """slug AAAA-MM-GG-N -> GG/MM/AAAA-N (solo per mostrarlo)."""
    m = re.match(r"^(\d{4})-(\d{2})-(\d{2})-(\d+)$", s or "")
    return f"{m[3]}/{m[2]}/{m[1]}-{m[4]}" if m else (s or "")


def web_url(slug):
    return f"{PUBLIC_BASE_URL}/entry.php?e={slug}" if PUBLIC_BASE_URL else ""


def entry_text(d):
    e = d["entry"]
    flags = " ".join(x for x in ("📌" if e["pinned"] else "", "🗄" if e["archived"] else "") if x)
    head = f"📝 <b>{esc(e['label'])}</b>  <code>{esc(it_slug(e['slug']))}</code>  <i>#{e['id']}</i>"
    meta = f"{esc(e['created'])} · {esc(e['source'])} · {e['wc']} parole"
    if e["updated"]:
        meta += f" · mod. {esc(e['updated'])}"
    if flags:
        meta += "  " + flags
    body = e["body"].strip()
    if len(body) > 1400:
        body = body[:1400].rstrip() + " …"
    parts = [head, f"<i>{meta}</i>", "", md(body)]
    if d.get("persons") or d.get("theme"):
        pl = []
        if d.get("persons"):
            pl.append("👤 " + ", ".join(esc(p["name"]) for p in d["persons"]))
        if d.get("theme"):
            pl.append("🗂 " + esc(d["theme"]["label"]))
        parts += ["", "  ·  ".join(pl)]
    if d["keywords"]:
        parts += ["🔑 " + " · ".join(esc(k["term"]) for k in d["keywords"][:8])]
    tl = []
    if d["tags"]["manual"]:
        tl.append(" ".join("#" + esc(t.replace(" ", "_")) for t in d["tags"]["manual"]))
    if d["tags"]["auto"]:
        tl.append("<i>auto:</i> " + " ".join(esc(t) for t in d["tags"]["auto"][:6]))
    if tl:
        parts += ["🏷 " + "  ".join(tl)]
    if d["related"]:
        parts += ["🔗 " + ", ".join(f"{esc(r['label'])} <i>({r['kind']} {r['score']}%)</i>"
                                    for r in d["related"][:5])]
    if d["mentions_in"] or d["mentions_out"]:
        mi = ", ".join(esc(x["label"]) for x in d["mentions_in"])
        mo = ", ".join(esc(x["label"]) for x in d["mentions_out"])
        if mo:
            parts.append("➡️ menziona: " + mo)
        if mi:
            parts.append("⬅️ citata in: " + mi)
    for ln in d.get("links", [])[:3]:
        if ln["status"] == "ok" and ln["title"]:
            parts.append(f"🌐 <a href=\"{esc(ln['url'])}\">{esc(ln['title'][:90])}</a> <i>— {esc(ln['site'])}</i>")
    if d["attachments"]:
        def att(a):
            t = {"pending": " · 🎙 trascrizione in corso", "done": " · 🎙 trascritto",
                 "error": " · 🎙 non trascritto"}.get(a.get("transcript", ""), "")
            return f"{esc(a['name'])} ({a['kind']}{t})"
        parts.append("📎 " + ", ".join(att(a) for a in d["attachments"]))
    if d["notes"]:
        parts.append("")
        for n in d["notes"][:5]:
            parts.append(f"🗒 <i>{esc(n['created'])}</i> — {esc(n['note'])}")
    return "\n".join(parts)


def entry_kb(d):
    e = d["entry"]
    s = e["slug"]
    rows = []
    nav = []
    if d["nav"]["prev"]:
        nav.append({"text": "◀", "callback_data": f"o:{d['nav']['prev']}"})
    nav.append({"text": "🎲", "callback_data": "rnd"})
    nav.append({"text": "🧭 simili", "callback_data": f"sim:{s}"})
    if d["nav"]["next"]:
        nav.append({"text": "▶", "callback_data": f"o:{d['nav']['next']}"})
    rows.append(nav)
    rows.append([
        {"text": "📌 sfissa" if e["pinned"] else "📌 fissa",
         "callback_data": f"set:{s}:p:{0 if e['pinned'] else 1}"},
        {"text": "🗄 ripristina" if e["archived"] else "🗄 archivia",
         "callback_data": f"set:{s}:a:{0 if e['archived'] else 1}"},
        {"text": "✎ modifica", "callback_data": f"ed:{s}"},
    ])
    rows.append([
        {"text": "🗒 nota", "callback_data": f"nt:{s}"},
        {"text": "🏷 +tag", "callback_data": f"tg:{s}"},
        {"text": "🗑 elimina", "callback_data": f"del:{s}"},
    ])
    extra = [{"text": f"👤 {p['name'][:20]}", "callback_data": f"pp:{p['id']}:1"} for p in d.get("persons", [])[:2]]
    if d.get("theme"):
        extra.append({"text": "🗂 tema", "callback_data": f"th:{d['theme']['id']}"})
    if extra:
        rows.append(extra)
    for r in d["related"][:3]:
        rows.append([{"text": f"🔗 {r['label'][:48]}", "callback_data": f"o:{r['slug']}"}])
    for m in (d["mentions_out"] + d["mentions_in"])[:3]:
        rows.append([{"text": f"↔ {m['label'][:48]}", "callback_data": f"o:{m['slug']}"}])
    if PUBLIC_BASE_URL:
        rows.append([{"text": "🌐 apri nel web", "url": web_url(s)}])
    return rows


def send_entry(chat_id, d, message_id=None):
    txt, kb = entry_text(d), entry_kb(d)
    if message_id:
        res = edit(chat_id, message_id, txt, kb)
        mid = message_id if res else None
    else:
        res = send(chat_id, txt, kb)
        mid = res["message_id"] if res else None
    if mid:
        CARD_MSGS[mid] = d["entry"]["slug"]
        if len(CARD_MSGS) > 400:
            for k in list(CARD_MSGS)[:200]:
                CARD_MSGS.pop(k, None)
    return mid


def list_kb(items, pager=None):
    rows = [[{"text": f"{it['label'][:46]}  ·  {it_slug(it['slug'])}",
             "callback_data": f"o:{it['slug']}"}] for i, it in enumerate(items)]
    if pager:
        kind, page, pages = pager
        nav = []
        if page > 1:
            nav.append({"text": "◀", "callback_data": f"{kind}:{page-1}"})
        nav.append({"text": f"{page}/{pages}", "callback_data": "noop"})
        if page < pages:
            nav.append({"text": "▶", "callback_data": f"{kind}:{page+1}"})
        rows.append(nav)
    return rows


def send_list(chat_id, title, items, pager=None, message_id=None):
    if not items:
        txt = title + "\n\n<i>niente.</i>"
        edit(chat_id, message_id, txt) if message_id else send(chat_id, txt)
        return
    kb = list_kb(items, pager)
    if message_id:
        edit(chat_id, message_id, title, kb)
    else:
        send(chat_id, title, kb, content=True)


# ------------------------------------------------------------------- hints

def hint_token(data):
    t = str(next(_TOK))
    HINTS[t] = data
    if len(HINTS) > 300:
        for k in list(HINTS)[:150]:
            HINTS.pop(k, None)
    return t


def send_hints(chat_id, slug, d):
    """Dopo un salvataggio: nomi propri da confermare come persone e tag che
    usi già altrove. Un tocco e il diario impara."""
    cands = (d or {}).get("candidates") or []
    sugg = (d or {}).get("suggest") or []
    if not cands and not sugg:
        return
    lines, rows = [], []
    for c in cands[:3]:
        t = hint_token({"kind": "person", "name": c, "slug": slug})
        lines.append(f"👤 <b>{esc(c)}</b> è una persona?")
        rows.append([{"text": f"✓ sì, {c[:24]}", "callback_data": f"h:{t}:y"},
                     {"text": "✕ no", "callback_data": f"h:{t}:n"}])
    if sugg:
        lines.append("🏷 Tag che usi già e che si adattano:")
        btn = [{"text": f"+ {s[:20]}", "callback_data": f"h:{hint_token({'kind': 'tag', 'name': s, 'slug': slug})}:y"}
               for s in sugg[:4]]
        rows.append(btn)
    rows.append([{"text": "fatto", "callback_data": "cancel"}])
    send(chat_id, "\n".join(lines), rows)


# ------------------------------------------------------------------- capture

def download(file_id):
    f = tg("getFile", file_id=file_id)
    if not f or "file_path" not in f:
        return None
    try:
        r = requests.get(f"https://api.telegram.org/file/bot{BOT_TOKEN}/{f['file_path']}", timeout=UPLOAD_T)
        return r.content
    except Exception as e:  # noqa: BLE001
        log.warning("download: %s", e)
        return None


def capture(msg, uid):
    chat_id = msg["chat"]["id"]
    text = msg.get("text") or msg.get("caption") or ""
    # tolleranza: un "\n" digitato/incollato letteralmente diventa un a-capo vero
    if "\\n" in text or "\\r" in text:
        text = text.replace("\\r\\n", "\n").replace("\\n", "\n").replace("\\r", "\n")
    payload = {
        "text": text, "from_id": uid, "chat_id": chat_id,
        "message_id": msg["message_id"], "update_id": 0,
        "date": msg.get("date", 0), "source": "telegram", "attachments": [],
    }
    files = {}
    audio = []          # (indice allegato, bytes) da trascrivere

    def grab(file_id, kind, name, mime):
        blob = download(file_id)
        if blob is None:
            return
        idx = len(payload["attachments"])
        files[f"file{idx}"] = (name, blob, mime)
        payload["attachments"].append({"kind": kind, "orig_name": name, "mime": mime, "tg_file_id": file_id})
        if kind in ("voice", "audio"):
            audio.append((idx, blob))

    if msg.get("photo"):
        p = msg["photo"][-1]
        grab(p["file_id"], "photo", f"photo_{p['file_unique_id']}.jpg", "image/jpeg")
    if msg.get("voice"):
        v = msg["voice"]
        grab(v["file_id"], "voice", f"voice_{v['file_unique_id']}.oga", v.get("mime_type") or "audio/ogg")
    if msg.get("audio"):
        a = msg["audio"]
        grab(a["file_id"], "audio", a.get("file_name") or f"audio_{a['file_unique_id']}.mp3", a.get("mime_type") or "audio/mpeg")
    if msg.get("video"):
        v = msg["video"]
        grab(v["file_id"], "video", v.get("file_name") or f"video_{v['file_unique_id']}.mp4", v.get("mime_type") or "video/mp4")
    if msg.get("document"):
        dd = msg["document"]
        grab(dd["file_id"], "document", dd.get("file_name") or f"doc_{dd['file_unique_id']}.bin", dd.get("mime_type") or "application/octet-stream")

    if not text and not files:
        return
    try:
        hdr = {"Authorization": "Bearer " + INGEST_TOKEN}
        if files:
            r = requests.post(INGEST_URL, data={"payload": json.dumps(payload)}, files=files, headers=hdr, timeout=UPLOAD_T)
        else:
            r = requests.post(INGEST_URL, json=payload, headers=hdr, timeout=HTTP_T)
        d = r.json()
    except Exception as e:  # noqa: BLE001
        send(chat_id, f"⚠️ errore di invio: {esc(e)}", reply_to=msg["message_id"])
        return
    if not d.get("ok"):
        extra = f"\nil tuo ID: {d['your_id']}" if "your_id" in d else ""
        send(chat_id, "⚠️ " + esc(d.get("error", "errore")) + extra, reply_to=msg["message_id"])
        return
    dup = " <i>(già presente)</i>" if d.get("duplicate") else ""
    full = api("entry", uid, ref=d["slug"])
    if full.get("ok"):
        send(chat_id, f"✓ salvato{dup}", reply_to=msg["message_id"])
        send_entry(chat_id, full)
        if not d.get("duplicate"):
            send_hints(chat_id, d["slug"], d)
    else:
        send(chat_id, f"✓ salvato{dup}: {web_url(d['slug'])}", preview=True, reply_to=msg["message_id"])
    if d.get("duplicate"):
        return
    if re.search(r"https?://", text):
        bg(fetch_links, uid, d["slug"])
    if audio:
        att_ids = [a.get("id") for a in d.get("attachments", [])]
        for idx, blob in audio:
            aid = att_ids[idx] if idx < len(att_ids) else None
            bg(transcribe_job, chat_id, uid, d["slug"], aid, blob)


def fetch_links(uid, slug):
    api("links_fetch", uid, ref=slug)


def transcribe_job(chat_id, uid, slug, att_id, blob):
    """Trascrive un vocale col servizio locale e porta il testo nella voce."""
    if not ML_URL:
        return
    note = send(chat_id, "🎙 trascrivo il vocale… <i>(può richiedere qualche minuto)</i>")
    t0 = time.time()
    try:
        hdr = {"Content-Type": "application/octet-stream"}
        if ML_TOKEN:
            hdr["Authorization"] = "Bearer " + ML_TOKEN
        r = requests.post(ML_URL + "/transcribe", data=blob, headers=hdr, timeout=TRANSCRIBE_T)
        res = r.json()
    except Exception as e:  # noqa: BLE001
        res = {"ok": False, "error": str(e)}
    if not res.get("ok") or not (res.get("text") or "").strip():
        api("transcript", uid, ref=slug, att_id=att_id, error=1)
        msg = "⚠️ trascrizione non riuscita: " + esc(res.get("error") or "nessun parlato riconosciuto")
        edit(chat_id, note["message_id"], msg) if note else send(chat_id, msg)
        return
    d = api("transcript", uid, ref=slug, att_id=att_id, text=res["text"])
    took = int(time.time() - t0)
    if note:
        edit(chat_id, note["message_id"],
             f"🎙 trascrizione pronta <i>({int(res.get('seconds', 0))} s di audio, {took} s)</i>")
    if d.get("ok"):
        def show():
            send_entry(chat_id, d)
            send_hints(chat_id, slug, d.get("hints"))
        deliver(chat_id, show, "la trascrizione del vocale è pronta")


# ------------------------------------------------------------------- comandi

def open_entry(chat_id, uid, ref, message_id=None):
    ref = ref.strip()
    # se è una data "nuda" (GG/MM/AAAA o ISO, senza -N): apri la voce di quel
    # giorno se è una sola, altrimenti mostra l'elenco del giorno.
    iso = to_iso_date(ref)
    if iso:
        d = api("day", uid, date=iso)
        items = d.get("items", []) if d.get("ok") else []
        if len(items) == 1:
            open_entry(chat_id, uid, items[0]["slug"], message_id)
        elif items:
            send_list(chat_id, f"📅 {esc(from_iso_date(iso))} — {len(items)} voci",
                      items, message_id=message_id)
        else:
            send(chat_id, f"Nessuna voce il {esc(from_iso_date(iso))}.")
        return
    d = api("entry", uid, ref=ref)
    if not d.get("ok"):
        send(chat_id, "⚠️ " + esc(d.get("error", "non trovata"))
             + "\n<i>Serve un id (<code>/e 7</code>) o uno slug completo "
               "(<code>/e 04/09/2026-8</code>). Solo la data apre/elenca quel giorno.</i>")
        return
    send_entry(chat_id, d, message_id)


def do_search(chat_id, uid, q, page=1, message_id=None):
    STATE.setdefault(chat_id, {})["q"] = q
    d = api("search", uid, q=q, page=page, per=8)
    if not d.get("ok"):
        send(chat_id, "⚠️ " + esc(d.get("error", "errore")))
        return
    title = f"🔎 <b>{esc(q)}</b> — {d['total']} risultati"
    if d.get("filters"):
        title += "\n<i>" + esc(d["filters"]) + "</i>"
    send_list(chat_id, title, d["items"], ("s", d["page"], d["pages"]), message_id)


def do_semantic(chat_id, uid, q):
    d = api("semantic", uid, q=q, n=8, _timeout=60)
    if not d.get("ok"):
        send(chat_id, "⚠️ " + esc(d.get("error", "errore")))
        return
    send_list(chat_id, f"🧭 <b>{esc(q)}</b> — le voci più vicine per significato", d["items"])


def do_tag(chat_id, uid, name, page=1, message_id=None):
    d = api("tag", uid, name=name, page=page, per=8)
    if not d.get("ok"):
        send(chat_id, "⚠️ " + esc(d.get("error", "errore")))
        return
    title = f"🏷 <b>{esc(d['name'])}</b> — {d['total']} voci"
    if d.get("cooc"):
        title += "\n<i>con:</i> " + " ".join(esc(c["name"]) for c in d["cooc"])
    send_list(chat_id, title, d["items"], (f"t~{name}", d["page"], d["pages"]), message_id)


def do_person(chat_id, uid, name=None, pid=None, page=1, message_id=None):
    d = api("person", uid, name=name or "", id=pid or 0, page=page, per=8)
    if not d.get("ok"):
        send(chat_id, "⚠️ " + esc(d.get("error", "errore")) + "\n<i>/persone per l'elenco.</i>")
        return
    title = f"👤 <b>{esc(d['name'])}</b> — {d['total']} voci"
    if d.get("aliases"):
        title += f"  <i>(alias: {esc(d['aliases'])})</i>"
    if d.get("with"):
        title += "\n<i>con:</i> " + ", ".join(esc(w) for w in d["with"])
    send_list(chat_id, title, d["items"], (f"pp:{d['id']}", d["page"], d["pages"]), message_id)


def do_persons(chat_id, uid):
    d = api("persons", uid)
    rows = d.get("persons", []) if d.get("ok") else []
    if not rows:
        send(chat_id, "Nessuna persona ancora. Quando scrivi un nome, te lo propongo io "
                      "dopo il salvataggio; oppure <code>/p Nome</code> dopo averla aggiunta dal web.")
        return
    kb = [[{"text": f"👤 {p['name']} · {p['count']}", "callback_data": f"pp:{p['id']}:1"}] for p in rows[:20]]
    send(chat_id, f"👤 <b>Persone</b> ({len(rows)})", kb, content=True)


def do_themes(chat_id, uid):
    d = api("themes", uid)
    rows = d.get("themes", []) if d.get("ok") else []
    if not rows:
        send(chat_id, "Ancora nessun tema: servono almeno due voci ben collegate fra loro.")
        return
    kb = [[{"text": f"🗂 {t['label']} · {t['size']}" + (" ✦" if t["recent"] else ""),
            "callback_data": f"th:{t['id']}"}] for t in rows[:15]]
    send(chat_id, "🗂 <b>Temi</b>  <i>(✦ = voci nelle ultime 2 settimane)</i>", kb, content=True)


def do_stats(chat_id, uid):
    d = api("stats", uid)
    if not d.get("ok"):
        send(chat_id, "⚠️ " + esc(d.get("error", "errore")))
        return
    lines = [
        "📊 <b>snippet</b>",
        f"voci <b>{d['entries']}</b> · parole <b>{d['words']}</b> (media {d['avg']})",
        f"giorni attivi <b>{d['active_days']}</b> · streak <b>{d['streak']}</b> gg",
        f"archi <b>{d['links']}</b> · note <b>{d['notes']}</b> · "
        f"tag {d['tags_m']}/{d['tags_a']} · isolate <b>{d['orphans']}</b>",
    ]
    if d["top_tags"]:
        lines.append("🏷 " + " · ".join(f"{esc(t['name'])} {t['count']}" for t in d["top_tags"]))
    kb = [[{"text": f"🔗 {h['label'][:50]} ({h['deg']})", "callback_data": f"o:{h['slug']}"}]
          for h in d["hubs"]]
    send(chat_id, "\n".join(lines), kb or None, content=True)


def do_saved(chat_id, uid, message_id=None):
    d = api("saved_list", uid)
    items = d.get("items", []) if d.get("ok") else []
    if not items:
        send(chat_id, "Nessuna ricerca salvata. Cerca con /search, poi la salvi dai risultati.")
        return
    kb = [[{"text": f"🔎 {s['name']}", "callback_data": f"run:{s['id']}"},
           {"text": "🗑", "callback_data": f"sdel:{s['id']}"}] for s in items]
    ttl = "<b>Ricerche salvate</b>"
    edit(chat_id, message_id, ttl, kb) if message_id else send(chat_id, ttl, kb)


def memories_message(chat_id, uid, quiet=False):
    """Ricordi: cosa scrivevi una settimana, un mese, un anno fa (oggi)."""
    d = api("memories", uid)
    groups = d.get("groups", []) if d.get("ok") else []
    if not groups:
        if not quiet:
            send(chat_id, "Nessun ricordo per oggi: in queste date passate non avevi scritto.")
        return False

    def show():
        lines = ["🕰 <b>Ricordi di oggi</b>"]
        items = []
        for g in groups:
            lines.append(f"\n<b>{esc(g['label'])}</b> <i>({esc(g['date'])})</i>")
            for it in g["items"][:3]:
                lines.append(f"• {esc(it['label'])}")
                items.append(it)
        send(chat_id, "\n".join(lines), list_kb(items[:8]), content=True)
    deliver(chat_id, show, "hai dei ricordi di oggi")
    return True


def digest_message(chat_id, uid, quiet=False):
    d = api("digest", uid, days=7)
    if not d.get("ok") or (not d.get("count") and quiet):
        return False
    if not d.get("count"):
        send(chat_id, "Negli ultimi 7 giorni non hai scritto nulla. Va bene così — quando vuoi, sono qui.")
        return True

    def show():
        lines = [f"🗓 <b>La tua settimana</b> — {d['count']} voci, {d['words']} parole"]
        if d["themes"]:
            lines.append("🗂 " + " · ".join(f"{esc(t['label'])} ({t['week']})" for t in d["themes"]))
        if d["persons"]:
            lines.append("👤 " + ", ".join(f"{esc(p['name'])} ({p['count']})" for p in d["persons"]))
        if d["tags"]:
            lines.append("🏷 " + " ".join(esc(t["name"]) for t in d["tags"]))
        if d.get("echo"):
            lines.append(f"\n🔁 <i>Dal passato, la voce più affine a questa settimana:</i>\n"
                         f"<b>{esc(d['echo']['label'])}</b> ({esc(d['echo']['day'])})")
        items = d["items"][-6:] + ([d["echo"]] if d.get("echo") else [])
        send(chat_id, "\n".join(lines), list_kb(items), content=True)
    deliver(chat_id, show, "il riepilogo della settimana è pronto")
    return True


DAYS_IT = ["", "lunedì", "martedì", "mercoledì", "giovedì", "venerdì", "sabato", "domenica"]


def settings_view(chat_id, uid, message_id=None):
    d = api("settings_get", uid)
    if not d.get("ok"):
        send(chat_id, "⚠️ " + esc(d.get("error", "errore")))
        return
    s = d["settings"]
    dd, dt = s["digest_at"].split(" ")
    on = lambda k: "🟢" if s[k] == "1" else "⚪"  # noqa: E731
    txt = ("🔔 <b>Notifiche</b>\n\n"
           f"{on('memories')} <b>Ricordi</b> — ogni giorno alle {esc(s['memories_at'])}, se una settimana, "
           "un mese o un anno fa avevi scritto\n"
           f"{on('digest')} <b>Riepilogo</b> — ogni {DAYS_IT[int(dd)]} alle {esc(dt)}\n"
           f"{on('nudge')} <b>Promemoria</b> — dopo {esc(s['nudge_days'])} giorni senza scrivere, alle {esc(s['nudge_at'])}\n\n"
           "<i>Per cambiare gli orari: </i><code>/notifiche ricordi 08:30</code> · "
           "<code>/notifiche riepilogo 7 20:00</code> <i>(giorno 1–7, 7 = domenica)</i> · "
           "<code>/notifiche promemoria 3 21:30</code>\n"
           "<i>Con il PIN attivo, finché il bot è bloccato ricevi solo un avviso senza contenuti.</i>")
    kb = [[{"text": f"{on('memories')} ricordi", "callback_data": "ns:memories"},
           {"text": f"{on('digest')} riepilogo", "callback_data": "ns:digest"},
           {"text": f"{on('nudge')} promemoria", "callback_data": "ns:nudge"}]]
    edit(chat_id, message_id, txt, kb) if message_id else send(chat_id, txt, kb)


def settings_cmd(chat_id, uid, arg):
    if not arg:
        settings_view(chat_id, uid)
        return
    p = arg.lower().split()
    sets = {}
    if p[0] in ("ricordi", "memories") and len(p) == 2:
        sets = {"memories_at": p[1], "memories": "1"}
    elif p[0] in ("riepilogo", "digest") and len(p) == 3:
        sets = {"digest_at": f"{p[1]} {p[2]}", "digest": "1"}
    elif p[0] in ("promemoria", "nudge") and len(p) == 3:
        sets = {"nudge_days": p[1], "nudge_at": p[2], "nudge": "1"}
    if not sets:
        send(chat_id, "Uso: <code>/notifiche ricordi 08:30</code> · <code>/notifiche riepilogo 7 20:00</code> · "
                      "<code>/notifiche promemoria 3 21:30</code>")
        return
    d = api("settings_set", uid, set=sets)
    if d.get("ok"):
        SCHED["settings_at"] = 0       # ricarica subito
        settings_view(chat_id, uid)
    else:
        send(chat_id, "⚠️ " + esc(d.get("error", "errore")))


def handle_command(chat_id, uid, text):
    parts = text.strip().split(maxsplit=1)
    cmd = parts[0].lower().lstrip("/").split("@")[0]
    arg = parts[1].strip() if len(parts) > 1 else ""

    if cmd in ("start", "help"):
        send(chat_id, HELP if cmd == "help" else
             f"snippet attivo. Il tuo ID: <code>{uid}</code>\n\n" + HELP)
    elif cmd == "last":
        n = int(arg) if arg.isdigit() else 10
        d = api("recent", uid, n=n)
        send_list(chat_id, f"🕑 ultime {n}", d.get("items", []))
    elif cmd in ("search", "s", "find", "cerca"):
        if not arg:
            STATE[chat_id] = {"await": "search"}
            send(chat_id, "Cosa cerco? <i>(puoi aggiungere filtri: tag:x persona:x da:GG/MM/AAAA)</i>", _CANCEL_KB)
        else:
            do_search(chat_id, uid, arg)
    elif cmd in ("sig", "significato", "semantic"):
        if not arg:
            STATE[chat_id] = {"await": "semantic"}
            send(chat_id, "Descrivi cosa cerchi, a parole tue.", _CANCEL_KB)
        else:
            do_semantic(chat_id, uid, arg)
    elif cmd == "tag":
        if not arg:
            send(chat_id, "Uso: /tag &lt;nome&gt;")
        else:
            do_tag(chat_id, uid, arg)
    elif cmd == "tags":
        d = api("tags", uid, limit=40)
        rows = d.get("tags", [])
        txt = "🏷 <b>tag</b>\n" + "  ".join(
            f"{esc(t['name'])}<i>·{t['count']}</i>" for t in rows) if rows else "Nessun tag."
        kb = [[{"text": f"{t['name']} ({t['count']})", "callback_data": f"t~{t['name']}:1"}]
              for t in rows[:12]]
        send(chat_id, txt, kb or None)
    elif cmd in ("persone", "people"):
        do_persons(chat_id, uid)
    elif cmd in ("p", "persona"):
        if not arg:
            do_persons(chat_id, uid)
        else:
            do_person(chat_id, uid, name=arg)
    elif cmd in ("temi", "themes"):
        do_themes(chat_id, uid)
    elif cmd in ("day", "today"):
        if cmd == "today":
            date = ""
        else:
            date = to_iso_date(arg)
            if arg and not date:
                send(chat_id, "Data non valida. Usa <code>GG/MM/AAAA</code>, es. 03/09/2026.")
                return
        d = api("day", uid, date=date)
        send_list(chat_id, f"📅 {esc(from_iso_date(d.get('date', '')))}", d.get("items", []))
    elif cmd in ("random", "r"):
        d = api("random", uid)
        if d.get("ok"):
            send_entry(chat_id, d)
        else:
            send(chat_id, "⚠️ " + esc(d.get("error", "vuoto")))
    elif cmd in ("e", "open", "voce"):
        if not arg:
            send(chat_id, "Apre una voce. Puoi passarle:\n"
                          "• l'id → <code>/e 7</code>\n"
                          "• lo slug → <code>/e 04/09/2026-8</code> (o <code>/e 2026-09-04-8</code>)\n"
                          "• solo una data → <code>/e 04/09/2026</code>: apre la voce di quel "
                          "giorno se è una sola, altrimenti le elenca.\n"
                          "id e slug sono sotto il titolo di ogni scheda.")
        else:
            open_entry(chat_id, uid, arg)
    elif cmd in ("ricordi", "memories"):
        memories_message(chat_id, uid)
    elif cmd in ("digest", "settimana", "riepilogo"):
        digest_message(chat_id, uid)
    elif cmd == "stats":
        do_stats(chat_id, uid)
    elif cmd == "saved":
        do_saved(chat_id, uid)
    elif cmd in ("notifiche", "notify"):
        settings_cmd(chat_id, uid, arg)
    elif cmd == "rebuild":
        send(chat_id, "Ricalcolo keyword, tag, persone, archi e temi di tutte le voci?",
             [[{"text": "Sì, ricalcola", "callback_data": "rebuildok"},
               {"text": "✕ annulla", "callback_data": "cancel"}]])
    elif cmd in ("annulla", "cancel"):
        STATE.pop(chat_id, None)
        send(chat_id, "Ok, annullato.")
    elif cmd == "lock":
        if not PIN:
            send(chat_id, "Gate PIN non attivo (SNIPPET_PIN non impostato).")
        else:
            AUTH.pop(chat_id, None)
            STATE.pop(chat_id, None)
            removed = 0
            for m in list(CARD_MSGS.keys()):
                if tg("deleteMessage", chat_id=chat_id, message_id=m):
                    removed += 1
                CARD_MSGS.pop(m, None)
            for (c, m) in list(CONTENT_MSGS):
                if c == chat_id and tg("deleteMessage", chat_id=chat_id, message_id=m):
                    removed += 1
                CONTENT_MSGS.discard((c, m))
            send(chat_id, f"🔒 bloccato. Rimossi {removed} messaggi con contenuti dalla chat "
                          "(solo quelli < 48 h). Inviami il PIN per rientrare.")
    elif cmd == "whoami":
        d = api("whoami", uid)
        send(chat_id, f"ID <code>{uid}</code> → utente <b>{esc(d.get('username','?'))}</b>"
             if d.get("ok") else f"ID <code>{uid}</code> — non autorizzato lato server")
    else:
        send(chat_id, "Comando sconosciuto. /help")


# ------------------------------------------------------------------- prompt/stato

def handle_awaited(chat_id, uid, st, text):
    what = st.get("await")
    STATE.pop(chat_id, None)
    if what == "search":
        do_search(chat_id, uid, text)
    elif what == "semantic":
        do_semantic(chat_id, uid, text)
    elif what == "edit":
        d = api("update", uid, ref=st["ref"], raw=text)
        if d.get("ok"):
            send(chat_id, "✓ aggiornata")
            send_entry(chat_id, d)
            if re.search(r"https?://", text):
                bg(fetch_links, uid, st["ref"])
        else:
            send(chat_id, "⚠️ " + esc(d.get("error", "errore")))
    elif what == "note":
        d = api("note_add", uid, ref=st["ref"], text=text)
        _after_mut(chat_id, d, "✓ nota aggiunta")
    elif what == "tag":
        d = api("tag_add", uid, ref=st["ref"], name=text)
        _after_mut(chat_id, d, "✓ tag aggiunto")
    elif what == "savename":
        d = api("saved_add", uid, name=text, q=st["q"])
        send(chat_id, "✓ ricerca salvata" if d.get("ok") else "⚠️ " + esc(d.get("error", "errore")))
    else:
        send(chat_id, "…ok")


def _after_mut(chat_id, d, okmsg):
    if d.get("ok"):
        send(chat_id, okmsg)
        if "entry" in d:
            send_entry(chat_id, d)
    else:
        send(chat_id, "⚠️ " + esc(d.get("error", "errore")))


def reply_target(chat_id, uid, rep):
    """Voce a cui si riferisce il messaggio a cui l'utente ha risposto:
    una scheda del bot (anche dopo un riavvio: l'#id è nel testo) o il
    messaggio originale con cui l'utente aveva creato la voce."""
    mid = rep.get("message_id")
    if mid in CARD_MSGS:
        return CARD_MSGS[mid]
    if rep.get("from", {}).get("is_bot"):
        first = (rep.get("text") or "").split("\n", 1)[0]
        if first.startswith("📝"):
            m = re.search(r"\s#(\d+)\s*$", first)
            if m:
                return m.group(1)
        return None
    d = api("by_tg", uid, chat_id=chat_id, message_id=mid)
    return d.get("slug") if d.get("ok") else None


# ------------------------------------------------------------------- callback

def on_callback(cq, uid):
    data = cq.get("data", "")
    msg = cq["message"]
    chat_id = msg["chat"]["id"]
    mid = msg["message_id"]
    cb_id = cq["id"]

    if data == "noop":
        answer_cb(cb_id)
        return
    if data == "cancel":
        STATE.pop(chat_id, None)
        answer_cb(cb_id, "Ok")
        tg("deleteMessage", chat_id=chat_id, message_id=mid)
        return
    if data == "rnd":
        answer_cb(cb_id)
        d = api("random", uid)
        if d.get("ok"):
            send_entry(chat_id, d, mid)
        return
    if data == "rebuildok":
        answer_cb(cb_id, "in corso…")
        tg("deleteMessage", chat_id=chat_id, message_id=mid)
        d = api("rebuild", uid, _timeout=300)
        send(chat_id, f"✓ {d.get('entries','?')} voci, {d.get('edges','?')} archi, {d.get('clusters','?')} temi"
             if d.get("ok") else "⚠️ " + esc(d.get("error", "errore")))
        return

    if data.startswith("o:"):
        answer_cb(cb_id)
        open_entry(chat_id, uid, data[2:], mid)
        return
    if data.startswith("s:"):
        answer_cb(cb_id)
        q = STATE.get(chat_id, {}).get("q", "")
        if q:
            do_search(chat_id, uid, q, int(data[2:]), mid)
        return
    if data.startswith("t~"):
        answer_cb(cb_id)
        rest = data[2:]
        name, _, page = rest.rpartition(":")
        do_tag(chat_id, uid, name, int(page) if page.isdigit() else 1, mid)
        return
    if data.startswith("pp:"):
        answer_cb(cb_id)
        _, pid, page = (data.split(":") + ["1"])[:3]
        is_list = msg.get("text", "").startswith("👤") and " voci" in msg.get("text", "").split("\n")[0]
        do_person(chat_id, uid, pid=int(pid), page=int(page) if page.isdigit() else 1,
                  message_id=mid if is_list else None)
        return
    if data.startswith("th:"):
        answer_cb(cb_id)
        d = api("theme", uid, id=int(data[3:]))
        if d.get("ok"):
            send_list(chat_id, f"🗂 <b>{esc(d['label'])}</b> — {len(d['items'])} voci", d["items"])
        else:
            send(chat_id, "⚠️ " + esc(d.get("error", "errore")))
        return
    if data.startswith("sim:"):
        answer_cb(cb_id)
        d = api("similar", uid, ref=data[4:])
        items = d.get("items", []) if d.get("ok") else []
        send_list(chat_id, "🧭 <b>Voci vicine per significato</b>" if items else
                  "🧭 Nessuna voce abbastanza vicina per significato (o vettori non ancora calcolati).", items)
        return
    if data.startswith("h:"):
        _, tok, yn = data.split(":")
        h = HINTS.pop(tok, None)
        if not h:
            answer_cb(cb_id, "suggerimento scaduto")
            return
        if h["kind"] == "person":
            if yn == "y":
                answer_cb(cb_id, "riconosco la persona in tutto il diario…")
                d = api("person_add", uid, name=h["name"], _timeout=300)
                send(chat_id, f"👤 <b>{esc(h['name'])}</b> aggiunto: compare in {d.get('entries', '?')} voci."
                     if d.get("ok") else "⚠️ " + esc(d.get("error", "errore")))
            else:
                api("person_ignore", uid, name=h["name"])
                answer_cb(cb_id, f"ok, {h['name']} non è una persona")
        elif h["kind"] == "tag":
            d = api("tag_add", uid, ref=h["slug"], name=h["name"])
            answer_cb(cb_id, f"+ {h['name']}" if d.get("ok") else d.get("error", "errore"))
        # togli il bottone usato dal messaggio dei suggerimenti
        kb = (msg.get("reply_markup") or {}).get("inline_keyboard") or []
        kb = [[b for b in row if not b.get("callback_data", "").startswith(f"h:{tok}:")] for row in kb]
        kb = [row for row in kb if row]
        if len(kb) <= 1:
            tg("deleteMessage", chat_id=chat_id, message_id=mid)
        else:
            tg("editMessageReplyMarkup", chat_id=chat_id, message_id=mid, reply_markup={"inline_keyboard": kb})
        return
    if data.startswith("rp:"):
        st = STATE.pop(chat_id, None) or {}
        pend = st.get("reply")
        if not pend:
            answer_cb(cb_id, "scaduto")
            tg("deleteMessage", chat_id=chat_id, message_id=mid)
            return
        answer_cb(cb_id)
        tg("deleteMessage", chat_id=chat_id, message_id=mid)
        how = data[3:]
        if how == "a":
            d = api("append", uid, ref=pend["ref"], text=pend["text"])
            _after_mut(chat_id, d, "✓ aggiunto alla voce")
            if d.get("ok"):
                send_hints(chat_id, d["entry"]["slug"], d.get("hints"))
        elif how == "n":
            d = api("note_add", uid, ref=pend["ref"], text=pend["text"])
            _after_mut(chat_id, d, "✓ nota aggiunta")
        elif how == "v":
            capture(pend["msg"], uid)
        return
    if data.startswith("ns:"):
        key = data[3:]
        cur = api("settings_get", uid).get("settings", {})
        d = api("settings_set", uid, set={key: "0" if cur.get(key) == "1" else "1"})
        SCHED["settings_at"] = 0
        answer_cb(cb_id, "fatto" if d.get("ok") else "errore")
        settings_view(chat_id, uid, mid)
        return
    if data.startswith("set:"):
        _, slug, field, val = data.split(":")
        kw = {"pinned" if field == "p" else "archived": int(val)}
        d = api("set", uid, ref=slug, **kw)
        answer_cb(cb_id, "fatto" if d.get("ok") else d.get("error", "errore"))
        if d.get("ok"):
            send_entry(chat_id, d, mid)
        return
    if data.startswith("ed:"):
        STATE[chat_id] = {"await": "edit", "ref": data[3:]}
        answer_cb(cb_id)
        cur = api("entry", uid, ref=data[3:])
        raw = cur.get("entry", {}).get("raw", "") if cur.get("ok") else ""
        send(chat_id, "Invia il nuovo testo della voce.\n<i>Testo attuale:</i>\n<pre>"
             + esc(raw[:3000]) + "</pre>", _CANCEL_KB)
        return
    if data.startswith("nt:"):
        STATE[chat_id] = {"await": "note", "ref": data[3:]}
        answer_cb(cb_id)
        send(chat_id, "Scrivi la nota.", _CANCEL_KB)
        return
    if data.startswith("tg:"):
        STATE[chat_id] = {"await": "tag", "ref": data[3:]}
        answer_cb(cb_id)
        send(chat_id, "Nome del tag da aggiungere.", _CANCEL_KB)
        return
    if data.startswith("del:"):
        slug = data[4:]
        answer_cb(cb_id)
        send(chat_id, f"Eliminare la voce <code>{esc(slug)}</code>?",
             [[{"text": "🗑 sì, elimina", "callback_data": f"delok:{slug}"},
               {"text": "✕ annulla", "callback_data": "cancel"}]])
        return
    if data.startswith("delok:"):
        d = api("delete", uid, ref=data[6:])
        answer_cb(cb_id, "eliminata" if d.get("ok") else "errore")
        tg("deleteMessage", chat_id=chat_id, message_id=mid)
        send(chat_id, "🗑 eliminata: " + esc(d.get("deleted", "")) if d.get("ok")
             else "⚠️ " + esc(d.get("error", "errore")))
        return
    if data.startswith("run:"):
        answer_cb(cb_id)
        sid = int(data[4:])
        lst = api("saved_list", uid)
        for s in lst.get("items", []):
            if s["id"] == sid:
                do_search(chat_id, uid, s["q"])
                return
        return
    if data.startswith("sdel:"):
        api("saved_del", uid, id=int(data[5:]))
        answer_cb(cb_id, "rimossa")
        do_saved(chat_id, uid, mid)
        return
    answer_cb(cb_id)


# ------------------------------------------------------------------- dispatch

def on_message(msg):
    if "from" not in msg or "chat" not in msg:
        return
    uid = msg["from"]["id"]
    chat_id = msg["chat"]["id"]
    if not ALLOWED_IDS or uid not in ALLOWED_IDS:
        log.info("ignoro messaggio da id non autorizzato: %s (chat %s)", uid, chat_id)
        return

    text = msg.get("text", "")

    # --- gate PIN: finché bloccato, nessun contenuto/comando; ogni messaggio
    #     è un tentativo di PIN e viene subito cancellato dalla chat ---
    if PIN and not gate_ok(chat_id):
        # Un messaggio NON testuale (foto, vocale, documento) non puo' essere un
        # PIN: non lo si cancella (si perderebbe il contenuto) e non consuma un
        # tentativo. Si avvisa soltanto.
        if not text.strip():
            send(chat_id, "🔒 <b>snippet è bloccato</b> — il contenuto non è stato "
                          "salvato. Inviami il PIN, poi rimandalo.")
            return
        # Il testo invece va rimosso subito: potrebbe essere il PIN.
        tg("deleteMessage", chat_id=chat_id, message_id=msg["message_id"])
        low = text.strip().lower().split("@")[0]
        if low in ("/start", "/help", "/lock"):
            send(chat_id, "🔒 <b>snippet è bloccato.</b> Inviami il PIN per sbloccare.")
            return
        res, extra = gate_try(chat_id, text)
        if res == "ok":
            send(chat_id, f"🔓 sbloccato — vale {PIN_TTL // 60} min, poi serve di nuovo "
                          "il PIN. <code>/lock</code> per bloccare subito.")
            flush_queue(chat_id)
        elif res == "locked":
            send(chat_id, f"🔒 troppi tentativi: riprova tra {extra}s.")
        else:
            send(chat_id, f"🔒 PIN errato. Tentativi rimasti: {extra}.")
        return

    # risposta a una scheda (o al messaggio che ha creato una voce):
    # si chiede se aggiungere il testo alla voce, farne una nota o una voce nuova
    rep = msg.get("reply_to_message")
    if rep and text and not text.startswith("/"):
        ref = reply_target(chat_id, uid, rep)
        if ref:
            STATE[chat_id] = {"reply": {"ref": ref, "text": text, "msg": msg}}
            send(chat_id, "Questo testo è il seguito della voce?",
                 [[{"text": "➕ aggiungi al testo", "callback_data": "rp:a"},
                   {"text": "🗒 come nota", "callback_data": "rp:n"}],
                  [{"text": "🆕 voce nuova", "callback_data": "rp:v"},
                   {"text": "✕ annulla", "callback_data": "cancel"}]],
                 reply_to=msg["message_id"])
            return

    if text.startswith("/"):
        STATE.pop(chat_id, None)
        handle_command(chat_id, uid, text)
        return

    st = STATE.get(chat_id)
    if st and st.get("await") and text:
        handle_awaited(chat_id, uid, st, text)
        return

    capture(msg, uid)


def handle(update):
    if "callback_query" in update:
        cq = update["callback_query"]
        uid = cq["from"]["id"]
        if not ALLOWED_IDS or uid not in ALLOWED_IDS:
            answer_cb(cq["id"])          # chiude lo spinner, nessun messaggio
            return
        if PIN and not gate_ok(cq["message"]["chat"]["id"]):
            answer_cb(cq["id"], "🔒 bloccato — invia il PIN in chat")
            return
        on_callback(cq, uid)
    elif "message" in update:
        on_message(update["message"])


# ------------------------------------------------------------------- notifiche

SCHED = {"settings": None, "settings_at": 0}


def _hm(s):
    h, m = s.split(":")
    return int(h) * 60 + int(m)


def scheduler():
    """Ogni minuto: ricordi, riepilogo settimanale, promemoria. I segnalibri
    di invio stanno nel DB (kv), cosi' un riavvio non fa doppioni."""
    time.sleep(20)
    while True:
        try:
            tick()
        except Exception:  # noqa: BLE001
            log.exception("scheduler")
        time.sleep(60)


def tick():
    if not ALLOWED_IDS:
        return
    uid = min(ALLOWED_IDS)
    chat_id = uid                      # chat privata: id chat = id utente
    now = datetime.now(TZ)
    if SCHED["settings"] is None or time.time() - SCHED["settings_at"] > 300:
        d = api("settings_get", uid)
        if not d.get("ok"):
            return
        SCHED["settings"], SCHED["state"], SCHED["settings_at"] = d["settings"], d["state"], time.time()
    s, st = SCHED["settings"], SCHED["state"]
    today = now.strftime("%Y-%m-%d")
    minute = now.hour * 60 + now.minute

    def mark(key, val):
        st[key] = val
        api("settings_set", uid, set={f"sent.{key}": val})

    if s["memories"] == "1" and minute >= _hm(s["memories_at"]) and st.get("memories") != today:
        mark("memories", today)
        memories_message(chat_id, uid, quiet=True)

    dday, dtime = s["digest_at"].split(" ")
    week = now.strftime("%G-W%V")
    if (s["digest"] == "1" and now.isoweekday() == int(dday) and minute >= _hm(dtime)
            and st.get("digest") != week):
        mark("digest", week)
        digest_message(chat_id, uid, quiet=True)

    if s["nudge"] == "1" and minute >= _hm(s["nudge_at"]) and st.get("nudge") != today:
        last = st.get("nudge") or ""
        days_since_nudge = (now.date() - datetime.strptime(last, "%Y-%m-%d").date()).days if last else 999
        if days_since_nudge >= int(s["nudge_days"]):
            d = api("idle", uid)
            if d.get("ok") and d["days"] >= int(s["nudge_days"]):
                mark("nudge", today)
                # nessun contenuto del diario: si puo' mandare anche col bot bloccato
                send(chat_id, f"✍️ Sono {d['days']} giorni che non scrivi. Com'è andata oggi?\n"
                              "<i>Anche due righe, o un vocale.</i>")


def main():
    me = tg("getMe")
    tg("setMyCommands", commands=[{"command": c, "description": d} for c, d in BOT_COMMANDS])
    if not ALLOWED_IDS:
        log.warning("ALLOWED_IDS vuoto: il bot ignora tutti fuorche' quanto ammesso lato server")
    else:
        log.info("ID Telegram ammessi: %s", sorted(ALLOWED_IDS))
    log.info("gate PIN: %s", ("attivo, finestra %d min" % (PIN_TTL // 60)) if PIN else "non attivo")
    log.info("trascrizione vocali: %s", ML_URL or "non configurata (ML_URL)")
    log.info("snippet-bot avviato come @%s (api: %s)", (me or {}).get("username", "?"), BOT_API_URL)
    threading.Thread(target=scheduler, daemon=True).start()
    offset = None
    while True:
        try:
            ups = tg("getUpdates", offset=offset, timeout=POLL_T,
                     allowed_updates=["message", "callback_query"]) or []
        except Exception as e:  # noqa: BLE001
            log.warning("getUpdates: %s", e)
            time.sleep(3)
            continue
        for u in ups:
            offset = u["update_id"] + 1
            try:
                handle(u)
            except Exception:  # noqa: BLE001
                log.exception("handle update %s", u.get("update_id"))


if __name__ == "__main__":
    main()
