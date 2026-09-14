-- snippet — schema del database SQLite.
-- Applicato una volta in fase di deploy (sqlite3 snippet.db < schema.sql);
-- la webapp lo esegue anche a runtime se il file DB non esiste ancora
-- (snippet_db_ensure() in lib.php).

PRAGMA journal_mode=WAL;
PRAGMA foreign_keys=ON;

-- ---------------------------------------------------------------------------
-- Utenti applicativi. snippet e' mono-utente: la tabella resta per compat con
-- lib.php (ereditato da RSSIntel) e per il bootstrap del primo admin da
-- login.php. I ruoli non hanno effetto pratico.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'admin',
  disabled      INTEGER NOT NULL DEFAULT 0,
  created_by    TEXT,
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  last_login_at TEXT
);

-- ---------------------------------------------------------------------------
-- Voci del diario.
--   raw   = testo esattamente come immesso (textarea web o messaggio Telegram),
--           usato per il round-trip in edit.php e per il riprocesso.
--   body  = testo ripulito dalle direttive, usato per rendering / FTS / keyword.
--   slug  = 'AAAA-MM-GG-<id>' (data locale di created_at + id): link mnemonici.
-- I timestamp sono UTC; la visualizzazione usa cfg()['timezone'].
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS entries (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  slug          TEXT UNIQUE,
  title         TEXT,
  body          TEXT NOT NULL,
  raw           TEXT NOT NULL DEFAULT '',
  lang          TEXT,
  source        TEXT NOT NULL DEFAULT 'web',   -- web | telegram | import
  author        TEXT NOT NULL,
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at    TEXT,
  word_count    INTEGER NOT NULL DEFAULT 0,
  char_count    INTEGER NOT NULL DEFAULT 0,
  pinned        INTEGER NOT NULL DEFAULT 0,
  archived      INTEGER NOT NULL DEFAULT 0,
  tg_chat_id    INTEGER,
  tg_message_id INTEGER,
  tg_from_id    INTEGER
);
CREATE INDEX IF NOT EXISTS idx_entries_created  ON entries(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_entries_pinned   ON entries(pinned, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_entries_archived ON entries(archived, created_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS idx_entries_tgmsg
  ON entries(tg_chat_id, tg_message_id) WHERE tg_message_id IS NOT NULL;

-- FTS5 self-contained (conserva il testo indicizzato -> snippet()/highlight()
-- restituiscono estratti reali, come in RSSIntel dal 2026-08-28).
CREATE VIRTUAL TABLE IF NOT EXISTS entries_fts USING fts5(
  title,
  body,
  tokenize='unicode61 remove_diacritics 2'
);

CREATE TRIGGER IF NOT EXISTS entries_ai AFTER INSERT ON entries BEGIN
  INSERT INTO entries_fts(rowid, title, body)
  VALUES (new.id, COALESCE(new.title,''), COALESCE(new.body,''));
END;
CREATE TRIGGER IF NOT EXISTS entries_ad AFTER DELETE ON entries BEGIN
  DELETE FROM entries_fts WHERE rowid = old.id;
END;
CREATE TRIGGER IF NOT EXISTS entries_au AFTER UPDATE OF title, body ON entries BEGIN
  DELETE FROM entries_fts WHERE rowid = old.id;
  INSERT INTO entries_fts(rowid, title, body)
  VALUES (new.id, COALESCE(new.title,''), COALESCE(new.body,''));
END;

-- ---------------------------------------------------------------------------
-- Keyword automatiche: parole piu' frequenti del corpo, al netto della
-- stoplist. Ricalcolate integralmente a ogni salvataggio/modifica.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS entry_keywords (
  entry_id INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
  term     TEXT NOT NULL,
  freq     INTEGER NOT NULL DEFAULT 1,
  rank     INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (entry_id, term)
);
CREATE INDEX IF NOT EXISTS idx_kw_term ON entry_keywords(term);

-- ---------------------------------------------------------------------------
-- Tag curati: manuali (#hashtag, direttiva !tag:, form web) o 'auto'
-- (promossi dalle keyword). entry_tags.auto distingue l'origine per-voce.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tags (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL UNIQUE,
  kind       TEXT NOT NULL DEFAULT 'manual',   -- manual | auto
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS entry_tags (
  entry_id INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
  tag_id   INTEGER NOT NULL REFERENCES tags(id)    ON DELETE CASCADE,
  auto     INTEGER NOT NULL DEFAULT 0,
  weight   REAL NOT NULL DEFAULT 1,
  PRIMARY KEY (entry_id, tag_id)
);
CREATE INDEX IF NOT EXISTS idx_entry_tags_tag ON entry_tags(tag_id);

-- ---------------------------------------------------------------------------
-- La "mappa": archi orientati fra voci. Le letture considerano sia src che
-- dst (grafo non orientato in lettura). kind:
--   manual   -> backlink [[..]] esplicito nel testo
--   keyword  -> keyword condivise
--   tag      -> tag condivisi
--   temporal -> vicinanza temporale (riservato, fase 3)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS links (
  src_id     INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
  dst_id     INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
  kind       TEXT NOT NULL,
  score      REAL NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (src_id, dst_id, kind)
);
CREATE INDEX IF NOT EXISTS idx_links_dst ON links(dst_id);

-- Note libere agganciate a una voce (come annotations di RSSIntel).
CREATE TABLE IF NOT EXISTS notes (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  entry_id   INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
  note       TEXT NOT NULL,
  author     TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_notes_entry ON notes(entry_id);

-- Allegati (foto/voce/documenti da Telegram). path e' relativo a
-- cfg()['attachments_dir']. transcript riservato a una futura trascrizione.
CREATE TABLE IF NOT EXISTS attachments (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  entry_id   INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
  kind       TEXT NOT NULL,            -- photo | voice | audio | document | video
  path       TEXT NOT NULL,
  mime       TEXT,
  bytes      INTEGER,
  orig_name  TEXT,
  tg_file_id TEXT,
  transcript TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_att_entry ON attachments(entry_id);

-- Stopword aggiuntive definite dall'utente (si sommano a stopwords.php).
CREATE TABLE IF NOT EXISTS stopwords_custom (
  word     TEXT PRIMARY KEY,
  added_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Ricerche salvate (ereditate da RSSIntel; mono-utente ma owner mantenuto).
CREATE TABLE IF NOT EXISTS saved_searches (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  owner      TEXT NOT NULL,
  name       TEXT NOT NULL,
  q          TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(owner, name)
);
CREATE INDEX IF NOT EXISTS idx_saved_searches_owner ON saved_searches(owner, name);

-- Throttling dei tentativi di login (per indirizzo IP). Senza questo il solo
-- freno era un usleep(0.3s): ~3 tentativi/s (audit 14/09/2026).
CREATE TABLE IF NOT EXISTS login_throttle (
  ip           TEXT PRIMARY KEY,
  fails        INTEGER NOT NULL DEFAULT 0,
  first_at     TEXT NOT NULL DEFAULT (datetime('now')),
  locked_until TEXT
);

-- Log grezzo degli update Telegram, per audit e riprocesso.
CREATE TABLE IF NOT EXISTS ingest_log (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tg_update_id  INTEGER,
  tg_message_id INTEGER,
  chat_id       INTEGER,
  from_id       INTEGER,
  raw_json      TEXT,
  received_at   TEXT NOT NULL DEFAULT (datetime('now')),
  entry_id      INTEGER,
  status        TEXT
);

-- Alla cancellazione di una voce il suo testo grezzo NON deve sopravvivere nel
-- log di ingest: "elimina" deve eliminare davvero (audit 14/09/2026).
CREATE TRIGGER IF NOT EXISTS entries_ad_purge_log AFTER DELETE ON entries BEGIN
  UPDATE ingest_log
     SET raw_json = NULL, status = 'purged'
   WHERE entry_id = old.id;
END;

-- Mittenti Telegram autorizzati (whitelist applicata anche lato bot).
CREATE TABLE IF NOT EXISTS tg_allowed (
  tg_user_id INTEGER PRIMARY KEY,
  username   TEXT NOT NULL,
  added_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
