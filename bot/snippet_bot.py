#!/usr/bin/env python3
"""
snippet - bot Telegram: interfaccia completa alla piattaforma.

Cattura (qualunque messaggio -> nuova voce, con allegati) e navigazione/
modifica dell'intero archivio: /last /search /tag /tags /day /random /stats
/saved, apertura voce con keyboard inline (naviga correlati, pin, archivia,
modifica, nota, tag, elimina, apri nel web).

Nessuna dipendenza pesante: long-polling grezzo su `requests`. Tutta la logica
di dati/NLP resta in PHP dietro `api/bot.php` e `api/ingest.php`.

Config da ambiente (vedi .env.example):
  BOT_TOKEN       token di @BotFather
  INGEST_TOKEN    = cfg()['ingest_token'] della webapp
  BOT_API_URL     .../snippet/api/bot.php
  INGEST_URL      .../snippet/api/ingest.php
  PUBLIC_BASE_URL https://host/snippet   (link "apri nel web")
  ALLOWED_IDS     lista di ID Telegram ammessi (csv); vuoto = solo il gate lato server
"""

import os
import re
import sys
import html
import time
import json
import logging

import requests

BOT_TOKEN = os.environ.get("BOT_TOKEN", "")
INGEST_TOKEN = os.environ.get("INGEST_TOKEN", "")
BOT_API_URL = os.environ.get("BOT_API_URL", "http://127.0.0.1/snippet/api/bot.php")
INGEST_URL = os.environ.get("INGEST_URL", "http://127.0.0.1/snippet/api/ingest.php")
PUBLIC_BASE_URL = os.environ.get("PUBLIC_BASE_URL", "").rstrip("/")
ALLOWED_IDS = {int(x) for x in os.environ.get("ALLOWED_IDS", "").replace(" ", "").split(",") if x}

if not BOT_TOKEN or not INGEST_TOKEN:
    print("BOT_TOKEN / INGEST_TOKEN mancanti (vedi .env.example)", file=sys.stderr)
    sys.exit(1)

TG = "https://api.telegram.org/bot" + BOT_TOKEN
HTTP_T, UPLOAD_T, POLL_T = 30, 120, 25

logging.basicConfig(format="%(asctime)s %(levelname)s %(message)s", level=logging.INFO)
log = logging.getLogger("snippet-bot")

STATE: dict = {}        # chat_id -> {"await": str, ...}
CARD_MSGS: dict = {}    # message_id -> slug  (per rispondere a una card = nuova nota)

_CANCEL_KB = [[{"text": "✕ annulla", "callback_data": "cancel"}]]

# Registrati su Telegram: appaiono nel menu "/" e nell'autocompletamento.
BOT_COMMANDS = [
    ("last", "ultime voci  [/last 20]"),
    ("search", "ricerca full-text  [/search parola]"),
    ("tag", "voci con un tag  [/tag viaggio]"),
    ("tags", "i tag più usati"),
    ("day", "voci di un giorno  [/day 04/09/2026]"),
    ("today", "voci di oggi"),
    ("random", "una voce a caso"),
    ("e", "apri una voce: id, slug o data  [/e 7 • /e 04/09/2026]"),
    ("stats", "numeri e streak"),
    ("saved", "ricerche salvate"),
    ("rebuild", "ricalcola le correlazioni"),
    ("help", "guida"),
]

HELP = (
    "<b>snippet</b> — diario con correlazione automatica\n\n"
    "Scrivi un messaggio qualsiasi → diventa una voce. Foto, note vocali e "
    "documenti vengono allegati.\n\n"
    "<b>Titolo</b>: <code>Titolo :: resto del pensiero</code> sulla prima riga, "
    "oppure prima riga breve e poi una riga vuota.\n"
    "<b>Direttive</b>: <code>#tag</code> · <code>[[123]]</code> collega · "
    "<code>!data:01/09/2026</code> · <code>!nolink</code> · <code>!pin</code> · "
    "<code>!tag:studio, viaggio</code>\n\n"
    "<b>Comandi</b>\n"
    "/last [n] — ultime voci\n"
    "/search &lt;testo&gt; — ricerca full-text (anche /s)\n"
    "/tag &lt;nome&gt; — voci con un tag · /tags — i tag più usati\n"
    "/day [GG/MM/AAAA] · /today · /random (/r)\n"
    "/e &lt;slug|id&gt; — apri una voce\n"
    "/stats — numeri e streak\n"
    "/saved — ricerche salvate\n"
    "/rebuild — ricalcola le correlazioni\n\n"
    "Rispondi a una card con del testo per aggiungerci una nota."
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
    body = {"cmd": cmd, "from_id": from_id, **kw}
    try:
        r = requests.post(BOT_API_URL, json=body,
                          headers={"Authorization": "Bearer " + INGEST_TOKEN},
                          timeout=HTTP_T)
        return r.json()
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "error": str(e)}


def send(chat_id, text, kb=None, preview=False, reply_to=None):
    p = {"chat_id": chat_id, "text": text, "parse_mode": "HTML",
         "disable_web_page_preview": not preview}
    if kb is not None:
        p["reply_markup"] = {"inline_keyboard": kb}
    if reply_to:
        p["reply_to_message_id"] = reply_to
    return tg("sendMessage", **p)


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


# ---------------------------------------------------------------- formattazione

def esc(s):
    return html.escape(str(s or ""))


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
    parts = [head, f"<i>{meta}</i>", "", esc(body)]
    if d["keywords"]:
        parts += ["", "🔑 " + " · ".join(esc(k["term"]) for k in d["keywords"][:10])]
    tl = []
    if d["tags"]["manual"]:
        tl.append(" ".join("#" + esc(t.replace(" ", "_")) for t in d["tags"]["manual"]))
    if d["tags"]["auto"]:
        tl.append("<i>auto:</i> " + " ".join(esc(t) for t in d["tags"]["auto"][:6]))
    if tl:
        parts += ["🏷 " + "  ".join(tl)]
    if d["related"]:
        parts += ["🔗 " + ", ".join(f"{esc(r['label'])} <i>({r['kind']} {r['score']})</i>"
                                    for r in d["related"][:5])]
    if d["mentions_in"] or d["mentions_out"]:
        mi = ", ".join(esc(x["label"]) for x in d["mentions_in"])
        mo = ", ".join(esc(x["label"]) for x in d["mentions_out"])
        if mo:
            parts.append("➡️ menziona: " + mo)
        if mi:
            parts.append("⬅️ citata in: " + mi)
    if d["attachments"]:
        parts.append("📎 " + ", ".join(f"{esc(a['name'])} ({a['kind']})" for a in d["attachments"]))
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
    edit(chat_id, message_id, title, kb) if message_id else send(chat_id, title, kb)


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

    def grab(file_id, kind, name, mime):
        blob = download(file_id)
        if blob is None:
            return
        idx = len(payload["attachments"])
        files[f"file{idx}"] = (name, blob, mime)
        payload["attachments"].append({"kind": kind, "orig_name": name, "mime": mime, "tg_file_id": file_id})

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
    else:
        send(chat_id, f"✓ salvato{dup}: {web_url(d['slug'])}", preview=True, reply_to=msg["message_id"])


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
    send_list(chat_id, title, d["items"], ("s", d["page"], d["pages"]), message_id)


def do_tag(chat_id, uid, name, page=1, message_id=None):
    d = api("tag", uid, name=name, page=page, per=8)
    if not d.get("ok"):
        send(chat_id, "⚠️ " + esc(d.get("error", "errore")))
        return
    title = f"🏷 <b>{esc(d['name'])}</b> — {d['total']} voci"
    if d.get("cooc"):
        title += "\n<i>con:</i> " + " ".join(esc(c["name"]) for c in d["cooc"])
    send_list(chat_id, title, d["items"], (f"t~{name}", d["page"], d["pages"]), message_id)


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
    send(chat_id, "\n".join(lines), kb or None)


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
    elif cmd in ("search", "s", "find"):
        if not arg:
            STATE[chat_id] = {"await": "search"}
            send(chat_id, "Cosa cerco?", _CANCEL_KB)
        else:
            do_search(chat_id, uid, arg)
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
    elif cmd == "stats":
        do_stats(chat_id, uid)
    elif cmd == "saved":
        do_saved(chat_id, uid)
    elif cmd == "rebuild":
        send(chat_id, "Ricalcolo keyword, tag e archi di tutte le voci?",
             [[{"text": "Sì, ricalcola", "callback_data": "rebuildok"},
               {"text": "✕ annulla", "callback_data": "cancel"}]])
    elif cmd in ("annulla", "cancel"):
        STATE.pop(chat_id, None)
        send(chat_id, "Ok, annullato.")
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
    elif what == "edit":
        d = api("update", uid, ref=st["ref"], raw=text)
        if d.get("ok"):
            send(chat_id, "✓ aggiornata")
            send_entry(chat_id, d)
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
        answer_cb(cb_id, "Annullato")
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
        d = api("rebuild", uid)
        send(chat_id, f"✓ {d.get('entries','?')} voci, {d.get('edges','?')} archi"
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

    # risposta a una card -> nota
    rep = msg.get("reply_to_message")
    if rep and rep.get("message_id") in CARD_MSGS and text and not text.startswith("/"):
        d = api("note_add", uid, ref=CARD_MSGS[rep["message_id"]], text=text)
        _after_mut(chat_id, d, "✓ nota aggiunta")
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
        on_callback(cq, uid)
    elif "message" in update:
        on_message(update["message"])


def main():
    me = tg("getMe")
    tg("setMyCommands", commands=[{"command": c, "description": d} for c, d in BOT_COMMANDS])
    if not ALLOWED_IDS:
        log.warning("ALLOWED_IDS vuoto: il bot ignora tutti fuorche' quanto ammesso lato server")
    else:
        log.info("ID Telegram ammessi: %s", sorted(ALLOWED_IDS))
    log.info("snippet-bot avviato come @%s (api: %s)", (me or {}).get("username", "?"), BOT_API_URL)
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
