# Changelog

## 2026-09-03 — Primo rilascio

Prima versione pubblica di **snippet**: diario privato con tagging e
correlazione automatici, webapp PHP/SQLite + bot Telegram Python.
Molte meccaniche (sessione/CSRF/utenti, stoplist, estrazione keyword,
correlazione per tag, ricerca FTS5, ricerche salvate) derivano da
[RSSIntel](https://github.com/Indr1d-C0ld/RSSIntel).

### Webapp

- `schema.sql`: `entries` (con `raw`/`body`/`slug`), `entries_fts` (FTS5
  self-contained), `entry_keywords`, `tags`/`entry_tags` (manuali + auto),
  `links` (grafo: `manual`/`keyword`/`tag`), `notes`, `attachments`,
  `saved_searches`, `stopwords_custom`, `users`, `tg_allowed`, `ingest_log`.
- `webapp/lib.php`: configurazione `cfg()`, sessione + CSRF, utenti/ruoli
  (mono-utente), helper data nel fuso locale, `snippet_db_ensure()` (applica
  lo schema al primo avvio), flash PRG, `entry_url()`, `word_count()`.
- `webapp/lib_nlp.php`: **unica** pipeline di analisi — `nlp_parse_directives()`
  (`#tag`, `[[..]]`, `!data:`, `!nolink`, `!pin`, `!tag:`, titolo),
  `nlp_extract_keywords()` (logica RSSIntel, stoplist EN+IT + `stopwords_custom`),
  rilevamento lingua, auto-tag, `entry_reindex()` (keyword + tag +
  correlazioni + backlink), `entry_save()` / `entry_update()`, `graph_rebuild()`,
  rendering sicuro del corpo con backlink cliccabili.
- Pagine: `compose.php` (scrittura, mobile-first), `diary.php` (cronologico,
  filtri, raggruppamento per giorno), `entry.php` (dettaglio + correlati +
  backlink + note + allegati + pin/archivia/elimina), `edit.php`,
  `search.php` (FTS5, `snippet()` evidenziato, filtri, ordinamento data/bm25,
  ricerche salvate), `tags.php` (elenco/dettaglio, co-occorrenze,
  rinomina/unisci, auto↔manuale, "ignora"→stopword, gestione stopword),
  `stats.php` (numeri, streak, attivita' 12 mesi, heatmap ora×giorno, hub,
  voci isolate, vocabolario), `map.php` + `map_data.php` + `assets/map.js`
  (grafo forza-diretto su canvas, nessuna dipendenza esterna),
  `login/logout/profile.php`, `notes.php` (JSON), `attachment.php` (streamer).
- API locali (guardia IP + bearer, `api/_guard.php`): `api/ingest.php`
  (voci da Telegram, JSON o multipart, whitelist `tg_allowed` + bootstrap,
  idempotenza su `(tg_chat_id, tg_message_id)`, allegati su disco),
  `api/recent.php` (`/last` `/find` `/tag`).
- PWA: `manifest.php`, `sw.js` (precache + offline), `assets/pwa.js` (coda
  bozze offline in IndexedDB con sincronizzazione al ritorno online),
  `offline.html`, icone generate con GD.

### Bot Telegram

- `bot/snippet_bot.py`: listener sottile (python-telegram-bot), inoltra testo,
  caption e allegati (foto/voce/audio/video/documenti) a `api/ingest.php`.
  Comandi `/start` `/help` `/last` `/find` `/tag`. Nessuna logica di analisi.
- `bot/.env.example`, `bot/requirements.txt`,
  `bot/deploy/snippet-bot.service.sample`.

### Strumenti e deploy

- `bin/snippet_maintenance.php --graph` (ricostruzione grafo, per il timer),
  `bin/snippet_tg.php` (whitelist mittenti), `bin/make_icons.php` (icone PWA).
  Tutti con guard `PHP_SAPI === 'cli'`.
- `deploy/htaccess.sample` (CSP, deny `config.php`/`*.db`/`api/_*.php`/`bin/`,
  passaggio header `Authorization`, `Service-Worker-Allowed`),
  `deploy/snippet-maintenance.{service,timer}.sample`.
- `README.md`, `LICENSE` (GPL-3.0-or-later), `.gitignore`.
