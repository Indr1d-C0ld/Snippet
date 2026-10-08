# snippet

Diario e raccolta di pensieri brevi, **self-hosted e privato**, che si organizza
da solo. Scrivi dal telefono, su Telegram o dal web, anche a voce: snippet
riconosce di cosa parli, chi nomini e con quali altre voci il pensiero è
collegato, e te lo restituisce come mappa, temi, ricordi e ricerche "per
significato". Tutta l'analisi gira sul tuo server: nessun testo, nessun audio
esce verso servizi esterni.

Stack: **PHP 8.1+** e **SQLite** (FTS5) per la webapp, **Python 3.11+** per il
bot Telegram e per il servizio ML locale (facoltativo: ONNX Runtime +
whisper.cpp). Nessun framework, nessun database server, nessuna build.
Ispirato per molte meccaniche a [RSSIntel](https://github.com/Indr1d-C0ld/RSSIntel).

## Cosa fa

**Scrivere**
- Un messaggio al bot (o il form web) diventa una voce. Foto, documenti e
  **note vocali** vengono allegati; i vocali sono **trascritti in locale**
  (whisper.cpp) e il testo entra nella voce, cercabile e correlato come il resto.
- **Rispondere a una scheda** nel bot aggiunge testo a quella voce (datato), o
  una nota: un pensiero si può riprendere nel tempo.
- Markdown leggero: `**grassetto**`, `*corsivo*`, elenchi, citazioni, link.
- Direttive: titolo `Titolo :: testo`, `#tag`, `[[123]]` collega, `!data:`,
  `!pin`, `!nolink`, `!tag:a, b`.

**Capire** (pipeline unica in `webapp/lib_nlp.php`)
- Termini ridotti alla radice con uno **stemmer italiano** (Snowball, PHP puro):
  "nazista", "nazisti" contano come lo stesso termine.
- **Keyword TF-IDF** sull'intero diario: pesa ciò che distingue una voce, non
  ciò che compare ovunque.
- **Auto-tag convergenti**: si riusano i tag che hai già (stessa radice) e se
  ne creano di nuovi solo per termini ripetuti e distintivi, così il vocabolario
  non esplode. Suggerimenti di **fusione** dei doppioni (con alias permanenti).
- **Persone**: i nomi propri vengono proposti dopo il salvataggio ("Nathan è
  una persona?"); confermata una volta, la persona è riconosciuta in tutto il
  diario (anche alias e `#hashtag`) e ha una pagina con la sua cronologia.
- **Correlazioni** combinate: stesse parole (coseno TF-IDF), stesso significato
  (embedding locale multilingual-e5-small), tag e persone in comune.
- **Temi**: gruppi di voci fortemente collegate, con un'etichetta automatica.
- **Anteprime dei link** citati: titolo ed estratto salvati (contro il
  link-rot), con protezioni anti-SSRF.

**Ritrovare**
- Ricerca unica con filtri nel testo (`tag:` `persona:` `da:` `a:` `tema:`
  `fonte:`) e tre modalità: **parole** (FTS5), **significato** (embedding),
  **mista** (le due classifiche fuse).
- Mappa forza-diretta (archi colorati per tipo, nodi per tema), pagine Persone
  e Temi, statistiche con calendario annuale dell'attività.
- Il bot **ti cerca**: ricordi ("un mese fa / un anno fa scrivevi…"),
  riepilogo settimanale, promemoria dopo qualche giorno di silenzio
  (`/notifiche`). Con il PIN attivo, finché il bot è bloccato arrivano solo
  avvisi senza contenuto.
- **Esporta**: ZIP in Markdown (una voce per file, metadati YAML, allegati,
  JSON completo; leggibile in Obsidian/Logseq), un "libro" stampabile per
  periodo e il suo PDF (Chromium headless).

**Proteggere**
- Mono-utente; sessione isolata dalle altre app del dominio; login con
  throttling; "Ricordami" a token ruotati (selector/validator, furto rilevato,
  revocabile per dispositivo); CSRF, CSP, `.htaccess` restrittivo.
- Bot vincolato agli ID ammessi, con gate PIN a finestra fissa.
- API solo da loopback con bearer token; servizio ML senza accesso alla rete.

## Componenti

| Percorso | Ruolo |
|---|---|
| `webapp/compose.php` · `edit.php` | scrittura e modifica (mobile-first) |
| `webapp/diary.php` · `entry.php` | cronologia; scheda voce con persone, tema, correlati, vicine per significato, fonti, allegati |
| `webapp/search.php` | ricerca con filtri e modalità parole/significato/mista, ricerche salvate |
| `webapp/tags.php` · `people.php` · `themes.php` | tag (fusioni suggerite), persone (conferme, alias, cronologia), temi |
| `webapp/map.php` · `stats.php` · `export.php` | mappa, statistiche, esportazione ZIP/libro/PDF |
| `webapp/lib.php` | configurazione, DB, **migrazioni**, sessione, "Ricordami", utenti |
| `webapp/lib_nlp.php` | **unica** pipeline: direttive, termini, TF-IDF, tag, persone, correlazioni, temi, rendering |
| `webapp/lib_stem.php` · `lib_ml.php` · `lib_links.php` · `lib_search.php` · `lib_export.php` | stemmer, client ML, anteprime link, motore di ricerca, esportazione |
| `webapp/migrations.php` | migrazioni di schema numerate (`PRAGMA user_version`) |
| `webapp/api/ingest.php` · `api/bot.php` | endpoint locali (bearer token) per il bot |
| `bot/snippet_bot.py` | bot Telegram: cattura, navigazione, modifica, trascrizione, notifiche |
| `ml/snippet_ml.py` | servizio locale: `/embed` (e5-small ONNX) e `/transcribe` (whisper.cpp) |
| `bin/snippet_maintenance.php` | manutenzione notturna: vocali in sospeso, anteprime, ricostruzione completa |
| `tests/run.php` · `tests/bot_harness.py` | test automatici (DB temporaneo) e banco di prova del bot senza Telegram |
| `schema.sql` | schema di base (versione 1); il resto arriva da `migrations.php` |
| `deploy/` · `bot/deploy/` · `ml/deploy/` | `.htaccess`, unit e timer systemd, script di installazione |

## Installazione

```bash
git clone https://github.com/Indr1d-C0ld/Snippet.git snippet && cd snippet

# 1. Configurazione (una cartella sopra la document root, oppure dentro webapp/)
cp config.sample.php config.php && $EDITOR config.php

# 2. Cartelle dati fuori dalla document root
sudo install -d -o www-data -g www-data /var/lib/snippet/attachments /var/lib/snippet/logs

# 3. Servire webapp/ con PHP; con Apache copiare deploy/htaccess.sample come
#    .htaccess (servono mod_headers e mod_rewrite). Database e migrazioni si
#    applicano da soli alla prima richiesta.
```

Al primo accesso `login.php` chiede di creare l'unico account.

### Servizio ML (facoltativo)

Ricerca per significato, correlazioni semantiche, trascrizione dei vocali.

```bash
sudo bash ml/deploy/install-ml.sh      # scarica pacchetti e modelli, compila whisper.cpp
# poi in config.php:  'ml_url' => 'http://127.0.0.1:8765', 'ml_token' => '<ML_TOKEN di /opt/snippet-ml/.env>'
```

Modelli: multilingual-e5-small ONNX quantizzato (≈135 MB) e un modello Whisper
(`ml/.env.example` confronta turbo / small / base). Su CPU modeste il modello
turbo è lento: la trascrizione gira comunque in background e a bassa priorità.

### Bot Telegram

```bash
cp bot/.env.example bot/.env && $EDITOR bot/.env   # BOT_TOKEN, ALLOWED_IDS, SNIPPET_PIN, ML_URL/ML_TOKEN
sudo bash bot/deploy/install-bot.sh                # /opt/snippet-bot + servizio systemd
php bin/snippet_tg.php --allow <tg_user_id> <username_web>
```

### Manutenzione

```bash
sudo cp deploy/snippet-maintenance.{service,timer}.sample /etc/systemd/system/   # togliere .sample
sudo systemctl enable --now snippet-maintenance.timer
```

### Test

```bash
php tests/run.php            # DB temporaneo, nessun dato reale toccato
BOT_API_URL=... INGEST_URL=... INGEST_TOKEN=... python3 tests/bot_harness.py   # contro un'istanza di prova
```

## Configurazione (`config.php`)

| Chiave | Significato |
|---|---|
| `db_path` · `attachments_dir` · `log_dir` | percorsi dei dati, fuori dalla document root |
| `ingest_token` · `ingest_allow_ip` · `ingest_bootstrap_from` | accesso del bot alle API locali |
| `session_name` · `session_cookie_path` | isolamento della sessione dalle altre app del dominio |
| `keywords_per_entry` · `autotags_new_per_entry` | keyword per voce; tag automatici *nuovi* per voce |
| `link_min_score` · `correlate_max_links` | affinità minima (0–1) e numero massimo di correlazioni automatiche |
| `ml_url` · `ml_token` · `ml_embed_model` | servizio ML locale (vuoto = disattivato) |
| `semantic_floor` · `semantic_query_k` | soglie per affinità di significato fra voci e nella ricerca |
| `link_fetch` | anteprime dei link citati (`false` = mai in rete) |
| `chromium_bin` | dove cercare Chromium per il PDF del libro |
| `timezone` · `site_name` | fuso di visualizzazione (il DB è in UTC), nome nell'intestazione |

## Licenza

GPL-3.0-or-later — vedi [`LICENSE`](LICENSE).
