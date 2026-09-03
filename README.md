# snippet

Diario e raccolta di pensieri brevi, self-hosted e privato, con **tagging e
correlazione automatici**. Ogni voce (scritta dal web o inviata a un bot
Telegram) viene analizzata: parole piu' frequenti, tag automatici, collegamenti
alle altre voci per keyword/tag condivisi e backlink espliciti `[[..]]`. Nasce
per prendere note in mobilita' — in viaggio, al lavoro, mentre si studia — e
ritrovarle collegate in una mappa.

Stack: **PHP 8.1+** e **SQLite** (FTS5) per la webapp, **Python 3.11+** per il
bot Telegram. Nessun framework, nessun database server, nessuna build.
Ispirato per molte meccaniche a [RSSIntel](https://github.com/Indr1d-C0ld/RSSIntel).

## Componenti

| Percorso | Ruolo |
|---|---|
| `webapp/compose.php` | scrittura di una voce (interfaccia mobile-first) |
| `webapp/diary.php` | vista cronologica (giorno / settimana / mese), filtro per tag |
| `webapp/entry.php` | dettaglio voce: testo, keyword, tag, correlati, backlink, note, allegati |
| `webapp/edit.php` | modifica sul testo grezzo (lo slug/permalink non cambia) |
| `webapp/search.php` | ricerca full-text FTS5, filtri, ordinamento per data o rilevanza, ricerche salvate |
| `webapp/tags.php` | elenco e gestione tag (rinomina/unisci, auto↔manuale, "ignora"), stopword personalizzate |
| `webapp/stats.php` | numeri, streak, attivita' nel tempo, heatmap oraria, hub, voci isolate, vocabolario |
| `webapp/map.php` + `map_data.php` + `assets/map.js` | grafo forza-diretto delle voci (canvas, nessuna dipendenza) |
| `webapp/api/ingest.php` | endpoint locale (bearer token) che riceve le voci dal bot |
| `webapp/api/recent.php` | elenco/ricerca rapida per i comandi del bot |
| `webapp/lib_nlp.php` | **unica** pipeline: direttive, keyword, auto-tag, correlazioni, backlink |
| `bot/snippet_bot.py` | bot Telegram sottile: inoltra messaggi e allegati a `api/ingest.php` |
| `bin/snippet_maintenance.php` | ricostruzione integrale del grafo (per il timer systemd) |
| `bin/snippet_tg.php` | gestione whitelist mittenti Telegram |
| `bin/make_icons.php` | (ri)genera le icone PWA con GD |
| `schema.sql` | schema del database SQLite |
| `deploy/` | esempi di `.htaccess`, unit e timer systemd |

## Schema dati (sintesi)

- `entries` — voci: `raw` (testo immesso) + `body` (ripulito) + `slug`
  (`AAAA-MM-GG-<id>`), metadati temporali UTC, `source` (web/telegram/import).
- `entries_fts` — indice FTS5 self-contained (`title`, `body`).
- `entry_keywords` — parole piu' frequenti per voce (stopword escluse).
- `tags` / `entry_tags` — tag manuali (`#tag`, `!tag:`) e automatici.
- `links` — archi fra voci: `manual` (`[[..]]`), `keyword`, `tag`.
- `notes`, `attachments`, `saved_searches`, `stopwords_custom`.
- `users` (mono-utente, bootstrap del primo admin), `tg_allowed`, `ingest_log`.

## Direttive nel corpo di una voce

- prima riga breve + riga vuota (o `# Titolo`) ⇒ titolo
- `#parola` ⇒ tag manuale · `[[123]]` / `[[2026-09-03-7]]` ⇒ collegamento
- `!data:AAAA-MM-GG[ HH:MM]` retrodata · `!nolink` niente correlazioni auto
- `!pin` fissa · `!tag:a, b, c` tag manuali

## Installazione

```bash
git clone https://github.com/Indr1d-C0ld/snippet.git
cd snippet

# 1. Configurazione (una cartella sopra la document root, oppure dentro webapp/)
cp config.sample.php config.php
$EDITOR config.php          # percorsi, ingest_token, fuso orario

# 2. Cartelle dati (fuori dalla document root)
sudo install -d -o www-data -g www-data /var/lib/snippet/attachments /var/lib/snippet/logs

# 3. Database — la webapp applica schema.sql al primo avvio; oppure a mano:
sqlite3 /var/lib/snippet/snippet.db < schema.sql

# 4. Servire webapp/ con PHP (Apache/nginx/php -S). Con Apache: copiare
#    deploy/htaccess.sample come webapp/.htaccess (CSP, deny config/db,
#    passaggio dell'header Authorization, scope del service worker).

# 5. Icone PWA (gia' versionate; per rigenerarle serve l'estensione GD)
php bin/make_icons.php
```

Al primo accesso `login.php` chiede di creare l'unico account.

### Bot Telegram (facoltativo)

```bash
python3 -m venv /opt/snippet-bot/venv
/opt/snippet-bot/venv/bin/pip install -r bot/requirements.txt
cp bot/.env.example /opt/snippet-bot/.env
$EDITOR /opt/snippet-bot/.env      # BOT_TOKEN, INGEST_TOKEN (= config.php), URL

cp bot/deploy/snippet-bot.service.sample /etc/systemd/system/snippet-bot.service
systemctl enable --now snippet-bot

# autorizzare il proprio ID (il bot lo mostra con /start):
php bin/snippet_tg.php --allow <tg_user_id> <username_web>
```

### Manutenzione periodica (facoltativo)

```bash
cp deploy/snippet-maintenance.service.sample /etc/systemd/system/snippet-maintenance.service
cp deploy/snippet-maintenance.timer.sample   /etc/systemd/system/snippet-maintenance.timer
systemctl enable --now snippet-maintenance.timer   # ricostruisce il grafo delle correlazioni
```

## Configurazione (`config.php`)

| Chiave | Significato |
|---|---|
| `db_path` | percorso assoluto del file SQLite |
| `attachments_dir` / `log_dir` | cartelle dati fuori dalla document root |
| `ingest_token` | bearer token condiviso con il bot (`api/ingest.php`) |
| `ingest_allow_ip` | IP ammessi a chiamare `api/` (il bot gira in locale) |
| `ingest_bootstrap_from` | ID Telegram auto-autorizzati al primo messaggio |
| `keywords_per_entry` / `autotags_per_entry` | quante keyword estrarre / promuovere a tag |
| `correlate_min_score` / `correlate_max_links` | soglia e tetto degli archi automatici |
| `timezone` | fuso di visualizzazione (i timestamp nel DB sono UTC) |

## Licenza

GPL-3.0-or-later — vedi [`LICENSE`](LICENSE).
