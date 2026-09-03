#!/usr/bin/env python3
"""
snippet - bot Telegram (listener sottile).

Non contiene logica di parsing/keyword/correlazione: inoltra ogni messaggio
all'endpoint locale api/ingest.php (bearer token), che fa tutto il lavoro in
PHP. Scarica gli allegati da Telegram e li ripassa in multipart.

Config da variabili d'ambiente (vedi .env.example):
  BOT_TOKEN         token di @BotFather
  INGEST_TOKEN      deve combaciare con cfg()['ingest_token'] della webapp
  INGEST_URL        es. http://127.0.0.1/snippet/api/ingest.php
  RECENT_URL        es. http://127.0.0.1/snippet/api/recent.php  (per /last /find /tag)
  PUBLIC_BASE_URL   es. https://tuo.host/snippet   (per comporre i link nelle risposte)
"""

import os
import sys
import json
import logging

import requests
from telegram import Update
from telegram.ext import (
    Application, CommandHandler, MessageHandler, ContextTypes, filters,
)

BOT_TOKEN = os.environ.get("BOT_TOKEN", "")
INGEST_TOKEN = os.environ.get("INGEST_TOKEN", "")
INGEST_URL = os.environ.get("INGEST_URL", "http://127.0.0.1/snippet/api/ingest.php")
RECENT_URL = os.environ.get("RECENT_URL", "")
PUBLIC_BASE_URL = os.environ.get("PUBLIC_BASE_URL", "").rstrip("/")

HTTP_TIMEOUT = 30
UPLOAD_TIMEOUT = 120

if not BOT_TOKEN or not INGEST_TOKEN:
    print("BOT_TOKEN / INGEST_TOKEN mancanti (vedi .env.example)", file=sys.stderr)
    sys.exit(1)

logging.basicConfig(
    format="%(asctime)s %(levelname)s %(message)s", level=logging.INFO
)
log = logging.getLogger("snippet-bot")

HELP = (
    "Scrivi liberamente: ogni messaggio diventa una voce.\n\n"
    "• 1ª riga breve + riga vuota = titolo\n"
    "• #parola = tag · [[123]] o [[2026-09-03-7]] = collega un'altra voce\n"
    "• !data:2026-09-01  retrodata\n"
    "• !nolink  niente correlazioni automatiche\n"
    "• !pin  fissa la voce · !tag:studio, viaggio\n\n"
    "Foto, note vocali, audio e documenti vengono allegati alla voce.\n"
    "Comandi: /last [n] · /find <parole> · /tag <nome>"
)


def _auth():
    return {"Authorization": "Bearer " + INGEST_TOKEN}


def _full_url(rel: str) -> str:
    if not rel:
        return ""
    if rel.startswith("http"):
        return rel
    if PUBLIC_BASE_URL:
        return PUBLIC_BASE_URL + "/" + rel.lstrip("/")
    return rel


async def cmd_start(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    u = update.effective_user
    await update.message.reply_text(
        "snippet attivo.\n"
        f"Il tuo ID Telegram: {u.id}\n"
        f"ID di questa chat: {update.effective_chat.id}\n\n"
        "Se il bot risponde «mittente non autorizzato», sul server:\n"
        f"  php bin/snippet_tg.php --allow {u.id} <username_web>\n\n"
        + HELP
    )


async def cmd_help(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text(HELP)


async def cmd_recent(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    if not RECENT_URL:
        await update.message.reply_text("Endpoint non configurato (RECENT_URL).")
        return
    args = ctx.args or []
    params = {}
    verb = (update.message.text or "").split()[0].lower()
    if verb.startswith("/tag") and args:
        params["tag"] = " ".join(args)
    elif verb.startswith("/find") and args:
        params["q"] = " ".join(args)
    elif args and args[0].isdigit():
        params["n"] = args[0]
    else:
        params["n"] = "10"
    try:
        r = requests.get(RECENT_URL, params=params, headers=_auth(), timeout=HTTP_TIMEOUT)
        data = r.json()
    except Exception as e:  # noqa: BLE001
        await update.message.reply_text(f"Errore: {e}")
        return
    if not data.get("ok"):
        await update.message.reply_text("Errore: " + str(data.get("error", "?")))
        return
    items = data.get("items", [])
    if not items:
        await update.message.reply_text("Nessuna voce.")
        return
    lines = [f"• {it['title']}\n  {_full_url(it['url'])}" for it in items]
    await update.message.reply_text("\n".join(lines), disable_web_page_preview=True)


async def _download(ctx, file_id, name, mime, bucket, meta, kind):
    try:
        tg_file = await ctx.bot.get_file(file_id)
        blob = bytes(await tg_file.download_as_bytearray())
    except Exception as e:  # noqa: BLE001
        log.warning("download allegato fallito (%s): %s", kind, e)
        return
    key = "file%d" % len(meta)
    bucket[key] = (name, blob, mime or "application/octet-stream")
    meta.append({"kind": kind, "orig_name": name, "mime": mime, "tg_file_id": file_id})


async def on_message(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    msg = update.message
    if msg is None:
        return

    text = msg.text or msg.caption or ""
    payload = {
        "text": text,
        "from_id": update.effective_user.id if update.effective_user else 0,
        "chat_id": update.effective_chat.id if update.effective_chat else 0,
        "message_id": msg.message_id,
        "update_id": update.update_id,
        "date": int(msg.date.timestamp()) if msg.date else 0,
        "source": "telegram",
        "attachments": [],
    }

    files: dict = {}
    meta = payload["attachments"]

    if msg.photo:
        ph = msg.photo[-1]
        await _download(ctx, ph.file_id, f"photo_{ph.file_unique_id}.jpg",
                        "image/jpeg", files, meta, "photo")
    if msg.voice:
        await _download(ctx, msg.voice.file_id, f"voice_{msg.voice.file_unique_id}.oga",
                        msg.voice.mime_type or "audio/ogg", files, meta, "voice")
    if msg.audio:
        a = msg.audio
        await _download(ctx, a.file_id, a.file_name or f"audio_{a.file_unique_id}.mp3",
                        a.mime_type, files, meta, "audio")
    if msg.video:
        v = msg.video
        await _download(ctx, v.file_id, v.file_name or f"video_{v.file_unique_id}.mp4",
                        v.mime_type, files, meta, "video")
    if msg.document:
        d = msg.document
        await _download(ctx, d.file_id, d.file_name or f"doc_{d.file_unique_id}.bin",
                        d.mime_type, files, meta, "document")

    if not text and not files:
        return  # niente da salvare (es. messaggio di servizio)

    try:
        if files:
            r = requests.post(INGEST_URL, data={"payload": json.dumps(payload)},
                              files=files, headers=_auth(), timeout=UPLOAD_TIMEOUT)
        else:
            r = requests.post(INGEST_URL, data=json.dumps(payload),
                              headers={**_auth(), "Content-Type": "application/json"},
                              timeout=HTTP_TIMEOUT)
        data = r.json()
    except Exception as e:  # noqa: BLE001
        await msg.reply_text(f"⚠️ Errore di invio: {e}")
        return

    if not data.get("ok"):
        err = str(data.get("error", "errore"))
        if "your_id" in data:
            err += f"\nIl tuo ID: {data['your_id']}"
        await msg.reply_text("⚠️ " + err)
        return

    suffix = " (già presente)" if data.get("duplicate") else ""
    n_att = len(data.get("attachments", []))
    att = f" · {n_att} allegat{'o' if n_att == 1 else 'i'}" if n_att else ""
    await msg.reply_text(f"✓ salvato{suffix}{att}\n{_full_url(data.get('url', ''))}",
                         disable_web_page_preview=True)


def main():
    app = Application.builder().token(BOT_TOKEN).build()
    app.add_handler(CommandHandler("start", cmd_start))
    app.add_handler(CommandHandler("help", cmd_help))
    app.add_handler(CommandHandler(["last", "find", "tag"], cmd_recent))
    app.add_handler(MessageHandler(~filters.COMMAND, on_message))
    log.info("snippet-bot avviato (ingest: %s)", INGEST_URL)
    app.run_polling(allowed_updates=Update.ALL_TYPES)


if __name__ == "__main__":
    main()
