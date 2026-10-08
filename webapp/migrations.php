<?php
declare(strict_types=1);

/**
 * snippet - migrazioni di schema, applicate da db_migrate() (lib.php).
 *
 * Chiave = versione di destinazione (PRAGMA user_version), valore = SQL o
 * callable(SQLite3). Ogni passo gira nella sua transazione e NON va mai
 * modificato dopo il rilascio: le correzioni si fanno con un passo nuovo.
 * schema.sql resta la base (versione 1).
 */

return [

  // 1 - base: schema.sql e' idempotente (CREATE ... IF NOT EXISTS). Sui DB
  //     nati prima delle migrazioni (user_version = 0) crea le tabelle che
  //     all'epoca nascevano "al volo" (login_throttle) e allinea tutto.
  1 => static function (SQLite3 $db): void {
    $p = schema_path();
    if ($p === null) return;
    // schema.sql imposta journal_mode, vietato dentro una transazione
    $sql = preg_replace('/^\s*PRAGMA\s+journal_mode\s*=\s*\w+\s*;\s*$/mi', '', (string)file_get_contents($p));
    $db->exec((string)$sql);
  },

  // 2 - kv: impostazioni e stato persistente (notifiche del bot, ecc.).
  2 => "
    CREATE TABLE IF NOT EXISTS kv (
      key        TEXT PRIMARY KEY,
      value      TEXT NOT NULL,
      updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
  ",

  // 3 - analisi del testo: termini stemmizzati (base del TF-IDF), punteggio
  //     delle keyword, persone, temi (cluster), vettori semantici.
  3 => "
    CREATE TABLE IF NOT EXISTS entry_terms (
      entry_id INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
      stem     TEXT NOT NULL,
      form     TEXT NOT NULL,              -- forma piu' frequente nella voce
      tf       INTEGER NOT NULL,
      PRIMARY KEY (entry_id, stem)
    ) WITHOUT ROWID;
    CREATE INDEX IF NOT EXISTS idx_entry_terms_stem ON entry_terms(stem);

    ALTER TABLE entry_keywords ADD COLUMN stem  TEXT;
    ALTER TABLE entry_keywords ADD COLUMN score REAL NOT NULL DEFAULT 0;
    CREATE INDEX IF NOT EXISTS idx_kw_stem ON entry_keywords(stem);

    CREATE TABLE IF NOT EXISTS persons (
      id         INTEGER PRIMARY KEY AUTOINCREMENT,
      name       TEXT NOT NULL UNIQUE COLLATE NOCASE,
      aliases    TEXT NOT NULL DEFAULT '',  -- separati da virgola
      note       TEXT,
      created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS entry_persons (
      entry_id  INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
      person_id INTEGER NOT NULL REFERENCES persons(id) ON DELETE CASCADE,
      mentions  INTEGER NOT NULL DEFAULT 1,
      PRIMARY KEY (entry_id, person_id)
    ) WITHOUT ROWID;
    CREATE INDEX IF NOT EXISTS idx_entry_persons_p ON entry_persons(person_id);
    -- nomi propri candidati scartati dall'utente (non riproporli)
    CREATE TABLE IF NOT EXISTS entity_ignore (
      name     TEXT PRIMARY KEY COLLATE NOCASE,
      added_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS clusters (
      id         INTEGER PRIMARY KEY,
      label      TEXT NOT NULL,
      size       INTEGER NOT NULL,
      terms      TEXT NOT NULL DEFAULT '',  -- termini caratterizzanti, csv
      updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    ALTER TABLE entries ADD COLUMN cluster INTEGER;

    CREATE TABLE IF NOT EXISTS entry_vectors (
      entry_id   INTEGER PRIMARY KEY REFERENCES entries(id) ON DELETE CASCADE,
      model      TEXT NOT NULL,
      dim        INTEGER NOT NULL,
      vec        BLOB NOT NULL,             -- float32 little-endian, normalizzato
      text_hash  TEXT NOT NULL,             -- per sapere quando ricalcolarlo
      updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
  ",

  // 4 - bot: stato della trascrizione dei vocali, anteprime dei link.
  4 => "
    ALTER TABLE attachments ADD COLUMN transcript_status TEXT;  -- pending|done|error
    CREATE TABLE IF NOT EXISTS link_previews (
      id          INTEGER PRIMARY KEY AUTOINCREMENT,
      entry_id    INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
      url         TEXT NOT NULL,
      status      TEXT NOT NULL DEFAULT 'pending',  -- pending|ok|error
      title       TEXT,
      description TEXT,
      site        TEXT,
      excerpt     TEXT,                    -- testo principale (copia anti link-rot)
      error       TEXT,
      fetched_at  TEXT,
      UNIQUE(entry_id, url)
    );
    CREATE INDEX IF NOT EXISTS idx_link_previews_status ON link_previews(status);
  ",

  // 5 - web: accesso persistente (\"Ricordami\"). Il cookie porta
  //     selector:validator; nel DB c'e' solo l'hash del validator.
  5 => "
    CREATE TABLE IF NOT EXISTS auth_tokens (
      id             INTEGER PRIMARY KEY AUTOINCREMENT,
      selector       TEXT NOT NULL UNIQUE,
      validator_hash TEXT NOT NULL,
      user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      user_agent     TEXT,
      ip             TEXT,
      created_at     TEXT NOT NULL DEFAULT (datetime('now')),
      last_used_at   TEXT,
      expires_at     TEXT NOT NULL
    );
  ",

  // 6 - alias dei tag: un tag fuso in un altro diventa un alias, cosi' ne'
  //     l'auto-tagging ne' un #hashtag scritto a mano lo fanno rinascere.
  6 => "
    CREATE TABLE IF NOT EXISTS tag_aliases (
      alias    TEXT PRIMARY KEY,
      tag_id   INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
      added_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
  ",
];
