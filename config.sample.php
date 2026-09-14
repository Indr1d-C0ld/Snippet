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

    // --- Motore keyword / correlazioni -----------------------------------
    'keywords_per_entry'  => 8,   // quante keyword estrarre per voce
    'autotags_per_entry'  => 5,   // quante delle keyword promuovere a tag 'auto'
    'correlate_min_score' => 2,   // soglia sotto la quale l'arco non viene salvato
    'correlate_max_links' => 12,  // max archi automatici in uscita per voce

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
