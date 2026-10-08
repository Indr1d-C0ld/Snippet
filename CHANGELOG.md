# Changelog

## 2026-10-08 — Roadmap: analisi del testo, bot proattivo, web completo

Revisione dell'intero progetto basata sull'uso reale (15 voci, tutte da
Telegram; 130 tag di cui 112 usati una sola volta; 2 soli archi da keyword;
login web ripetuto a ogni visita dal telefono) e quattro blocchi di lavoro.
Schema del database dalla versione 1 alla 6 tramite migrazioni automatiche.
Test: 99 controlli PHP (`tests/run.php`) e 22 scenari del bot
(`tests/bot_harness.py`), tutti verdi.

### Fondamenta
- **Migrazioni di schema** (`webapp/migrations.php` nuovo, `webapp/lib.php`):
  passi numerati applicati da `db_migrate()` secondo `PRAGMA user_version`,
  ognuno in transazione. `schema.sql` resta la base (v1). Nuove tabelle: `kv`,
  `entry_terms`, `persons`, `entry_persons`, `entity_ignore`, `clusters`,
  `entry_vectors`, `link_previews`, `auth_tokens`, `tag_aliases`.
- **Test automatici** (`tests/` nuovo): runner senza dipendenze su DB
  temporaneo; `SNIPPET_CONFIG` in `lib.php` permette una configurazione di prova.
- **Backup**: `bin/snippet_backup.sh` legge il DB in `mode=ro` (vede anche il
  WAL; `immutable` lo ignorava, rischiando di perdere scritture recenti).
- **`.htaccess`** (`deploy/htaccess.sample`): negati anche `tests/`,
  `migrations.php` e qualunque copia `*.bak`, `*.orig`, `*~`.

### Analisi del testo (`webapp/lib_nlp.php`, `lib_stem.php` nuovo)
- **Stemmer italiano** Snowball in PHP puro, identico all'implementazione di
  riferimento su 1.843 parole (oracolo nei test).
- **Termini e TF-IDF**: le keyword si pesano sull'intero diario; in corpo e
  titolo, con premio al titolo. Nuova tabella `entry_terms`.
- **Auto-tag convergenti**: riuso dei tag esistenti per radice; tag nuovi solo
  per termini ripetuti e distintivi (`autotags_new_per_entry`, default 3).
  Su dati reali: da 95 a 15 associazioni automatiche.
- **Persone**: candidati dai nomi propri non a inizio frase, ordinati con un
  elenco di nomi di battesimo (`webapp/firstnames.php` nuovo) e filtrati
  (luoghi, `-ismo`, parole che compaiono anche in minuscolo). Una persona
  confermata è riconosciuta ovunque (alias e `#hashtag`), non diventa mai
  auto-tag e conta nelle correlazioni.
- **Correlazioni**: punteggio 0–1 che combina coseno TF-IDF, coseno degli
  embedding, Jaccard sui tag e persone in comune; tipo dell'arco = componente
  prevalente (`keyword`, `semantic`, `tag`, `person`). Archi `temporal` per le
  voci a meno di 24 h. Soglie calibrate sul diario reale.
- **Temi**: propagazione deterministica delle etichette sul grafo, etichette
  dai termini caratterizzanti (`graph_clusters()`).
- **Suggerimenti** di tag esistenti per voce e di **fusioni** di doppioni
  (stessa radice, stessa famiglia `-ista/-ismo`, nome con/senza spazi,
  refusi); la fusione riscrive gli `#hashtag` e crea un **alias** permanente.
- **Correzioni**: il filtro dei verbi scartava i nomi in `-isti` (nazisti,
  artisti); "bene", "reale" e altri aggettivi di giudizio ora sono stopword
  (`webapp/stopwords.php`).
- `webapp/tags.php`: **Rinomina/unisci** ed **Elimina** ora agiscono anche sul
  testo delle voci (prima il vecchio tag rinasceva alla modifica successiva).

### Servizio ML locale (`ml/` nuovo, `webapp/lib_ml.php` nuovo)
- `ml/snippet_ml.py`: `/embed` (multilingual-e5-small, ONNX quantizzato) e
  `/transcribe` (whisper.cpp, una trascrizione alla volta, `nice 19`). Solo
  127.0.0.1, token, e l'unità systemd vieta qualunque rete esterna.
- `ml/deploy/install-ml.sh`: installazione idempotente (scarica o riusa venv,
  modelli e binario).

### Bot (`bot/snippet_bot.py`, `webapp/api/bot.php`, `webapp/api/ingest.php`)
- **Trascrizione dei vocali** in background; il testo diventa la voce (o si
  accoda alla didascalia) ed è cercabile e correlato.
- **Notifiche**: ricordi (una settimana, uno/tre/sei mesi, N anni fa),
  riepilogo settimanale (temi, persone, tag, "dal passato"), promemoria dopo N
  giorni di silenzio. `/notifiche` per attivarle e regolarne gli orari;
  segnalibri nel DB (niente doppioni dopo un riavvio). Con il PIN attivo il
  contenuto resta in coda e arriva allo sblocco; in chat va solo un avviso.
- **Risposta a una scheda** (o al proprio messaggio originale): aggiungi al
  testo (datato) / come nota / voce nuova.
- Dopo il salvataggio: "è una persona?" e tag suggeriti, a un tocco.
- Nuovi comandi: `/sig`, `/persone`, `/p`, `/temi`, `/ricordi`, `/digest`,
  `/notifiche`; `/search` con filtri e modalità mista; bottoni 🧭 simili,
  👤 persona, 🗂 tema; formattazione Markdown nelle schede; `/lock` cancella
  anche liste e riepiloghi.
- API: `persons`, `person`, `person_add`, `person_ignore`, `themes`, `theme`,
  `semantic`, `similar`, `hints`, `append`, `by_tg`, `transcript`,
  `links_fetch`, `memories`, `digest`, `idle`, `settings_get/set`.

### Web
- **"Ricordami"** (`webapp/lib.php`, `login.php`, `logout.php`,
  `profile.php`): token selector/validator con hash nel DB, rotazione a ogni
  uso, furto rilevato, 30 giorni dall'ultimo uso, elenco dispositivi e revoca;
  il cambio password revoca gli altri dispositivi.
- **Markdown leggero** sicuro (`entry_render_body()`, `md_inline()`): tutto
  il testo passa da `h()` prima di ogni trasformazione; link solo http/https.
- **Ricerca** (`webapp/lib_search.php` nuovo, `search.php`): filtri nel testo,
  modalità parole / significato / mista (Reciprocal Rank Fusion), soglia
  semantica relativa alla query, ripiego automatico per la sintassi FTS.
- **Pagine nuove**: `people.php`, `themes.php`, `export.php`; in `entry.php`
  persone, tema, voci vicine per significato, tag suggeriti, fonti citate,
  lettore audio e stato delle trascrizioni; in `stats.php` il calendario
  dell'anno; in `map.php` sei tipi di arco e nodi colorati per tema.
- **Anteprime dei link** (`webapp/lib_links.php` nuovo): solo IP pubblici
  (DNS verificato e imposto a curl), redirect ricontrollati, 2 MB / 8 s.
- **Esportazione** (`webapp/lib_export.php` nuovo): ZIP Markdown con front
  matter + allegati + JSON; libro stampabile; PDF con Chromium headless.

### Manutenzione
- `bin/snippet_maintenance.php`: vocali in sospeso, anteprime in attesa,
  ricostruzione completa con i vettori mancanti
  (`deploy/snippet-maintenance.service.sample` aggiornato).
- `config.sample.php`: chiavi nuove documentate (`autotags_new_per_entry`,
  `link_min_score`, `ml_*`, `semantic_*`, `link_fetch`, `chromium_bin`);
  `autotags_per_entry` e `correlate_min_score` non sono più usate.

## 2026-09-14 — Audit completo: sicurezza, ciclo di vita dei dati, operativita'

Revisione integrale della piattaforma (webapp, API, bot, script, deploy,
configurazione, storia git). Risultati puliti su: SQL injection, XSS, CSRF,
path traversal, efficacia dell'`.htaccess`, gate bearer+IP delle API, gate PIN
del bot, integrita' del database, assenza di segreti nella storia git.
Di seguito i difetti trovati e corretti.

### Sicurezza
- **Isolamento della sessione** (`webapp/lib.php`, `config.sample.php`). La
  sessione usava il nome di default `PHPSESSID` su path `/`: ogni altra
  applicazione PHP sullo stesso dominio condivideva lo stesso file di sessione
  e le stesse chiavi (`uid`, `uname`, `role`), quindi autenticarsi su una
  valeva come autenticarsi su snippet. Ora il nome del cookie e il path sono
  configurabili (`session_name`, `session_cookie_path`) e il flag `secure`
  viene attivato da solo quando la richiesta arriva in HTTPS.
- **Nessuna sessione negli endpoint API** (`webapp/api/_guard.php`): definisce
  `SNIPPET_NO_SESSION`, cosi' le chiamate del bot non creano file di sessione.
- **`webapp/attachment.php`**: il `Content-Type` non viene piu' rimandato tale
  e quale dal database (dato che proviene dal client di ingest). Solo i tipi in
  whitelist vengono serviti inline; tutto il resto diventa un download opaco.
  In particolare `image/svg+xml` non e' in whitelist: un SVG servito inline
  sulla stessa origine puo' eseguire script.
- **Throttling del login** (`webapp/login.php`, `webapp/lib.php`, `schema.sql`):
  nuova tabella `login_throttle`; oltre 8 tentativi falliti in 15 minuti l'IP
  resta bloccato 15 minuti e le credenziali non vengono nemmeno valutate.
  Prima l'unico freno era un `usleep(0.3s)`.

### Ciclo di vita dei dati
- **`ingest_log` non conserva piu' il testo delle voci cancellate**
  (`schema.sql`): nuovo trigger `entries_ad_purge_log` che a ogni
  `DELETE` su `entries` azzera `raw_json` e marca la riga come `purged`.
  "Elimina" ora elimina davvero.
- **I file degli allegati vengono rimossi dal disco** (`webapp/lib_nlp.php`,
  `entry.php`, `api/bot.php`): nuova `entry_delete()` che legge i percorsi
  prima della cancellazione, elimina la voce e poi rimuove i file (con lo
  stesso prefix-check di `attachment.php`) e la cartella se vuota. Prima il
  `CASCADE` rimuoveva solo le righe, lasciando i file orfani per sempre.
- **Il pin non viene piu' azzerato da una modifica** (`webapp/lib_nlp.php`):
  `entry_update()` preservava `archived` ma resettava `pinned`, quindi ogni
  modifica, `+tag` o `tag_del` spinnava la voce. Il pin e' uno stato della
  voce, non del testo: la direttiva `!pin` puo' solo attivarlo.

### Qualita' dell'auto-tagging
- `webapp/lib_nlp.php`, `webapp/stopwords.php`: esclusi anche gli avverbi in
  `-mente` (>= 9 lettere, per non toccare *clemente/veemente/demente*) e il
  futuro semplice (`-ra'`, `-ro'`, `-rai`, `-remo`, `-rete`, `-ranno`);
  aggiunte ~30 forme verbali. Il suffisso nominale `-iere` e' ora escluso
  dalla regola sugli infiniti (*mestiere, quartiere, carrozziere* restano
  keyword) e `parere`/`volere` sono in whitelist.

### Bot
- Un messaggio non testuale (foto, vocale, documento) inviato mentre il bot e'
  bloccato non viene piu' cancellato e non consuma un tentativo di PIN: veniva
  perso senza essere salvato.

### Operativita'
- `bin/snippet_backup.sh` (nuovo): snapshot locale di database (`VACUUM INTO`
  da sorgente `immutable`) e allegati, con verifica di leggibilita' e rotazione
  degli ultimi N. `deploy/snippet-backup.{service,timer}.sample` (giornaliero).
- `deploy/install.sh`: installa e abilita anche i timer `snippet-maintenance`
  (ricostruzione del grafo) e `snippet-backup`, che finora non erano mai stati
  messi in servizio.

## 2026-09-04 — Gate PIN sul bot + rifiniture

### Gate PIN (bot/snippet_bot.py)
- PIN di sblocco opzionale (`SNIPPET_PIN` nel `.env`). Finché non lo si invia
  in chat, il bot **non mostra contenuti e non esegue comandi**; ogni messaggio
  è un tentativo di PIN e viene **cancellato subito** dalla chat (il PIN non
  resta in cronologia). Copre anche i tap sui pulsanti delle schede.
- Finestra di validità **fissa** (`SNIPPET_PIN_TIMEOUT`, default 1800 s): scaduta,
  il PIN va reinserito a prescindere dall'attività. Ogni riavvio del servizio
  ri-blocca (lo stato di sblocco vive solo in RAM).
- 5 tentativi errati → 5 minuti di blocco (durante i quali nemmeno il PIN
  corretto passa).
- Nuovo comando **`/lock`**: ri-blocca subito e cancella le schede voce ancora
  rimovibili (finestra di 48 h di Telegram).
- `bot/.env.example`: documentate `SNIPPET_PIN` / `SNIPPET_PIN_TIMEOUT`.

### Rifiniture bot
- Comandi registrati su Telegram con `setMyCommands` (compaiono nel menu «/»).
- Pulsante **«✕ annulla»** ora funzionante ovunque (conferme di eliminazione,
  `/rebuild`, prompt di modifica/nota/tag/ricerca): dà un feedback e rimuove
  il messaggio; prima era un `noop` silenzioso.
- `/e` accetta id, slug `AAAA-MM-GG-N`, slug italiano `GG/MM/AAAA-N` e una
  **data nuda** `GG/MM/AAAA` (apre la voce del giorno o la elenca).
  `entry_resolve_ref()` e i backlink `[[..]]` accettano lo slug italiano;
  il bot mostra lo slug come `GG/MM/AAAA-N` e l'id come `#N`.
- Read-timeout del long-poll portato a `timeout`+10 s (elimina i «Read timed
  out» spuri nel log).

## 2026-09-04 — Interfaccia bot Telegram completa

Il bot passa da semplice "cattura" a **client completo della piattaforma**.

### API
- `webapp/api/bot.php` (nuovo): endpoint di comando (guardia bearer + IP
  locale). Lettura: `recent`, `search`, `entry` (payload completo: keyword,
  tag, correlati, backlink, navigazione crono), `day`, `random`, `tags`,
  `tag`, `stats`, `saved_list`. Scrittura: `saved_add/del`, `update`, `set`
  (pin/archivia), `delete`, `note_add/del`, `tag_add/del`, `sw_add`,
  `rebuild`, `whoami`.
- `webapp/api/_guard.php`: nuovo `api_sender()` — risolve l'ID Telegram in
  username applicativo (whitelist `tg_allowed` + bootstrap da
  `ingest_bootstrap_from`). `webapp/api/ingest.php` rifattorizzato per usarlo.

### Bot
- `bot/snippet_bot.py`: riscritto. Long-polling grezzo su `requests` (nessun
  framework). Tastiere inline: naviga correlati/backlink, ◀▶ cronologico,
  pin, archivia, modifica, nota, +tag, elimina (con conferma), apri nel web.
  Prompt multi-step (modifica/nota/tag/ricerca) con pulsante «✕ annulla»
  funzionante — prima il pulsante di annullo era un `noop` silenzioso.
  Rispondere a una scheda con del testo = nuova nota. Cattura testo, foto,
  note vocali, audio, video, documenti. `setMyCommands` all'avvio (i comandi
  compaiono nel menu "/"). Timeout di lettura del long-poll = `timeout`+10s.
- `bot/requirements.txt`: solo `requests` (rimosso `python-telegram-bot`).
- `bot/deploy/install-bot.sh` (nuovo): deploy come servizio systemd
  (`snippet-bot.service`, utente dedicato, venv, `Restart=always`).

### Data/ora in formato italiano
- `GG/MM/AAAA` e fuso di Roma anche nelle risposte del bot; `stats.php` mesi
  come `MM/AAAA`; `offline.html` in `it-IT`/`Europe/Rome`.
- La direttiva `!data:` e i comandi `/day` e `/e` accettano `GG/MM/AAAA`
  (oltre all'ISO). `entry_resolve_ref()` e i backlink `[[..]]` accettano lo
  slug in forma italiana `GG/MM/AAAA-N`. `/e <data>` apre la voce di quel
  giorno (o le elenca).

### Titolo dal messaggio + auto-tag
- `nlp_parse_directives()`: nuovo separatore su una riga `Titolo :: corpo`
  (comodo su mobile, non spezza gli URL); un `
` letterale digitato/incollato
  viene convertito in a-capo (lato bot).
- `nlp_looks_like_verb()` + filtro in `nlp_extract_keywords()`: esclude le
  forme verbali dall'estrazione keyword e dagli auto-tag (infiniti anche con
  enclitico, gerundi, `-uto`, `-ono`, imperfetti `-ava/-eva/-avo/-evo`,
  participi `-ato` ≥7 lettere con whitelist di nomi comuni).
  `webapp/stopwords.php`: +~150 forme verbali frequenti.
- `webapp/lib_nlp.php`: i tag aggiunti fuori dal corpo (form web, bot) vengono
  fusi nel `raw` come `#hashtag`, così sopravvivono a `entry_update()`.

### Webapp
- `webapp/compose.php`: risposta JSON con `ajax=1` (usata dalla coda bozze
  offline della PWA); `<meta name="csrf">` e `id` sul form.

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
