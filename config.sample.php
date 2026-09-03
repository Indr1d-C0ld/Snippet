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

    // Fuso orario di visualizzazione (i timestamp nel DB sono UTC).
    'timezone'        => 'Europe/Rome',

    // Etichetta mostrata nell'header.
    'site_name'       => 'snippet',
];
