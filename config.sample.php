<?php
declare(strict_types=1);

/**
 * snippet - configurazione.
 *
 * Copia questo file in `config.php` (una cartella sopra la document root, cosi'
 * come e' servita la webapp; oppure dentro `webapp/` per un layout piatto) e
 * adatta i valori. `config.php` NON va versionato nel repo pubblico.
 *
 * Gli stessi valori rilevanti per il bot Telegram (ingest_url, ingest_token)
 * sono replicati nel suo `.env` — vedi bot/.env.example.
 */

return [
    // Percorso assoluto del database SQLite (leggibile/scrivibile dall'utente
    // del webserver). La webapp applica schema.sql al primo avvio se manca.
    'db_path'         => '/var/lib/snippet/snippet.db',

    // Cartelle dati fuori dalla document root.
    'attachments_dir' => '/var/lib/snippet/attachments',
    'log_dir'         => '/var/lib/snippet/logs',

    // --- Ingest da Telegram (api/ingest.php) --------------------------------
    // Token bearer condiviso col bot. Genera un valore reale con:
    //   php -r 'echo bin2hex(random_bytes(32)), "\n";'
    'ingest_token'    => 'CHANGE_ME',
    // IP ammessi a chiamare api/ingest.php (il bot gira sullo stesso host).
    'ingest_allow_ip' => ['127.0.0.1', '::1'],
    // Tetto in byte per singolo allegato accettato dall'ingest.
    'attach_max_bytes' => 20 * 1024 * 1024,
    // ID Telegram che, al primo messaggio, vengono aggiunti in automatico a
    // `tg_allowed` (legati all'unico account web). Vuoto = whitelist solo
    // esplicita via `php bin/snippet_tg.php --allow <id> <username>`.
    'ingest_bootstrap_from' => [],

    // --- Analisi del testo e correlazioni (rivisto il 08/10/2026) ---------
    // Keyword = termini (radici italiane) pesati TF-IDF sull'intero diario.
    'keywords_per_entry'     => 8,
    // Quanti tag automatici NUOVI puo' creare una voce. I tag che usi gia'
    // altrove (stessa radice) vengono riusati senza tetto: il vocabolario
    // converge invece di frammentarsi. (Sostituisce 'autotags_per_entry'.)
    'autotags_new_per_entry' => 3,
    // Affinita' minima (0..1) per salvare una correlazione automatica, e
    // quante al massimo per voce. (Sostituisce 'correlate_min_score'.)
    'link_min_score'         => 0.08,
    'correlate_max_links'    => 8,

    // --- Servizio ML locale (facoltativo; ml/snippet_ml.py) ----------------
    // Ricerca per significato, voci simili, correlazioni semantiche e
    // trascrizione dei vocali. Vuoto = disattivato: tutto il resto funziona.
    'ml_url'           => '',            // es. 'http://127.0.0.1:8765'
    'ml_token'         => '',            // = ML_TOKEN in /opt/snippet-ml/.env
    'ml_embed_model'   => 'multilingual-e5-small',
    // Coseno fra due voci sotto il quale non c'e' affinita' di significato
    // (e5-small: la mediana fra voci qualsiasi e' ~0.81).
    'semantic_floor'   => 0.83,
    // Ricerca per significato: risultati sopra media + k * deviazione standard.
    'semantic_query_k' => 1.0,

    // --- Anteprime dei link citati nelle voci ------------------------------
    // Titolo ed estratto delle pagine citate (contro il link-rot). Solo
    // http/https verso IP pubblici, max 2 MB / 8 s. false = mai in rete.
    'link_fetch'       => true,

    // --- Esportazione in PDF (export.php) ----------------------------------
    // Percorsi in cui cercare Chromium per generare il PDF del "libro".
    'chromium_bin'     => ['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome'],

    // --- Isolamento della sessione -------------------------------------
    // Nome del cookie di sessione: DEVE essere diverso da quello di ogni altra
    // applicazione PHP ospitata sullo stesso dominio. Con il default `PHPSESSID`
    // due app condividono lo stesso file di sessione (stesso save_path) e
    // quindi le stesse chiavi (`uid`, `uname`): autenticarsi su una varrebbe
    // come autenticarsi sull'altra.
    'session_name'        => 'SNIPPETSESS',
    // Path del cookie. Se l'app e' servita in una sottocartella (es. /snippet/)
    // restringilo a quella: il cookie non viene nemmeno inviato alle altre app.
    'session_cookie_path' => '/',

    // Fuso orario di visualizzazione (i timestamp nel DB sono UTC).
    'timezone'        => 'Europe/Rome',

    // Etichetta mostrata nell'header.
    'site_name'       => 'snippet',
];
