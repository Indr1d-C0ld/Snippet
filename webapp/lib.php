<?php
declare(strict_types=1);

/**
 * snippet - funzioni condivise.
 * Base ereditata da RSSIntel/webapp/lib.php (sessione, CSRF, utenti, helper
 * data), piu' l'ensure automatico dello schema e alcuni helper propri.
 */

/** Configurazione da config.php (memoizzata per l'intera richiesta). */
function cfg(): array {
  static $c = null;
  if ($c === null) {
    $f = dirname(__DIR__) . '/config.php';
    if (!is_file($f)) {
      $f = __DIR__ . '/config.php'; // layout piatto (webapp servita dalla root)
    }
    if (!is_file($f)) {
      http_response_code(500);
      die('config.php mancante: copia config.sample.php in config.php e adatta i valori.');
    }
    $c = require $f;
  }
  return $c;
}

/** Percorso di schema.sql (accanto a config.php o dentro webapp/). */
function schema_path(): ?string {
  foreach ([dirname(__DIR__) . '/schema.sql', __DIR__ . '/schema.sql'] as $p) {
    if (is_file($p)) return $p;
  }
  return null;
}

/**
 * Crea il file DB e applica schema.sql se non esiste ancora. Idempotente e
 * a basso costo (un is_file() per richiesta). Evita il passo manuale
 * "sqlite3 snippet.db < schema.sql" in fase di deploy.
 */
function snippet_db_ensure(): void {
  static $done = false;
  if ($done) return;
  $done = true;

  $path = cfg()['db_path'];
  if (is_file($path) && filesize($path) > 0) return;

  $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $db = new SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
  $db->busyTimeout(5000);
  $sql = schema_path();
  if ($sql !== null) {
    $db->exec((string)file_get_contents($sql));
  }
  $db->close();
}

function db_ro(): SQLite3 {
  snippet_db_ensure();
  $db = new SQLite3(cfg()['db_path'], SQLITE3_OPEN_READONLY);
  $db->busyTimeout(3000);
  $db->enableExceptions(true);
  $db->exec('PRAGMA foreign_keys=ON;');
  return $db;
}

function db_rw(): SQLite3 {
  snippet_db_ensure();
  $db = new SQLite3(cfg()['db_path'], SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
  $db->busyTimeout(5000);
  $db->enableExceptions(true);
  $db->exec('PRAGMA journal_mode=WAL;');
  $db->exec('PRAGMA foreign_keys=ON;');
  return $db;
}

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* =====================  Data / ora  ===================== */

function tzobj(): DateTimeZone {
  static $tz = null;
  if ($tz === null) {
    try { $tz = new DateTimeZone((string)(cfg()['timezone'] ?? 'UTC')); }
    catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
  }
  return $tz;
}

/** Timestamp corrente in UTC, formato del DB. */
function now_utc(): string {
  return gmdate('Y-m-d H:i:s');
}

/**
 * Data/ora UTC del DB -> formato italiano nel fuso configurato.
 *   fmt_dt('2026-09-03 21:14:34')        -> '03/09/2026 23:14'
 *   fmt_dt('2026-09-03 21:14:34', false) -> '03/09/2026'
 */
function fmt_dt(?string $s, bool $with_time = true): string {
  $s = trim((string)$s);
  if ($s === '' || str_starts_with($s, '0000-00-00')) return '';
  try {
    $dt = new DateTime($s, new DateTimeZone('UTC'));
    $dt->setTimezone(tzobj());
    return $dt->format($with_time ? 'd/m/Y H:i' : 'd/m/Y');
  } catch (Throwable $e) {
    return $s;
  }
}

/** Giorno di calendario 'AAAA-MM-GG' -> 'GG/MM/AAAA'. */
function fmt_day(string $s): string {
  return preg_match('~^(\d{4})-(\d{2})-(\d{2})~', $s, $m) ? "$m[3]/$m[2]/$m[1]" : $s;
}

/** Data locale 'AAAA-MM-GG' a partire da un timestamp UTC del DB. */
function local_ymd(string $utc): string {
  try {
    $dt = new DateTime($utc, new DateTimeZone('UTC'));
    $dt->setTimezone(tzobj());
    return $dt->format('Y-m-d');
  } catch (Throwable $e) {
    return substr($utc, 0, 10);
  }
}

/* =====================  Sessione + CSRF  ===================== */

if (session_status() === PHP_SESSION_NONE) {
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    // 'secure' => true,  // abilitare quando servito solo via HTTPS
  ]);
  session_start();
}
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function csrf_token(): string {
  return (string)($_SESSION['csrf'] ?? '');
}

function csrf_check(): bool {
  $t = (string)($_POST['csrf'] ?? '');
  return $t !== '' && hash_equals((string)($_SESSION['csrf'] ?? ''), $t);
}

/* =====================  Flash (PRG)  ===================== */

function flash_set(string $type, string $msg): void {
  $_SESSION['flash'] = [$type, $msg];
}
/** Ritorna [type, msg] una sola volta, poi la consuma. */
function flash_take(): ?array {
  $f = $_SESSION['flash'] ?? null;
  unset($_SESSION['flash']);
  return is_array($f) ? $f : null;
}

/* =====================  Utenti  ===================== */

function users_schema(): string {
  return "
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
  ";
}

function users_ensure(SQLite3 $dbw): void {
  $dbw->exec(users_schema());
}

/**
 * Riga dell'utente autenticato (id, username, role, disabled) o null.
 * Riallinea la sessione a ogni richiesta e la invalida se l'utente e' stato
 * disabilitato o rimosso.
 */
function auth_user(): ?array {
  static $cache = null;
  if ($cache !== null) return $cache ?: null;

  if (empty($_SESSION['uid'])) { $cache = false; return null; }
  try {
    $db = db_ro();
    $st = $db->prepare('SELECT id, username, role, disabled, created_at, last_login_at FROM users WHERE id = :id');
    $st->bindValue(':id', (int)$_SESSION['uid'], SQLITE3_INTEGER);
    $r = $st->execute()->fetchArray(SQLITE3_ASSOC);
  } catch (Throwable $e) {
    $r = false;
  }
  if (!$r || (int)$r['disabled'] === 1) {
    $_SESSION = [];
    $cache = false;
    return null;
  }
  $_SESSION['uname'] = $r['username'];
  $_SESSION['role']  = $r['role'];
  $cache = $r;
  return $r;
}

function current_user(): string { return (string)($_SESSION['uname'] ?? ''); }
function current_role(): string { return (string)($_SESSION['role'] ?? ''); }

/** snippet e' mono-utente: l'unico account e' admin. */
function is_admin(string $u = ''): bool { return current_role() === 'admin'; }
/** Sempre vero per l'utente autenticato (nessun ruolo di sola lettura). */
function can_annotate(): bool { return auth_user() !== null; }

/** Redirige a login.php se non autenticato. */
function require_login(): void {
  if (auth_user() === null) {
    $next = (string)($_SERVER['REQUEST_URI'] ?? '');
    header('Location: login.php' . ($next !== '' ? '?next=' . urlencode($next) : ''));
    exit;
  }
}

/** Compat con RSSIntel: mono-utente -> equivale a require_login(). */
function require_role(string ...$roles): void {
  require_login();
}

/* =====================  Helper voci  ===================== */

/** URL della pagina dettaglio di una voce (accetta riga o slug/id). */
function entry_url(array|string|int $e): string {
  if (is_array($e)) {
    $ref = (string)($e['slug'] ?? '') !== '' ? (string)$e['slug'] : (string)($e['id'] ?? '');
  } else {
    $ref = (string)$e;
  }
  return 'entry.php?e=' . rawurlencode($ref);
}

/** Conta parole "vere" (sequenze di lettere/cifre). */
function word_count(string $s): int {
  $parts = preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
  return $parts ? count($parts) : 0;
}

/** Prima riga non vuota, troncata, per usarla come "titolo di ripiego". */
function first_line(string $s, int $max = 90): string {
  foreach (preg_split('/\R/u', $s) as $ln) {
    $ln = trim($ln);
    if ($ln !== '') {
      return mb_strlen($ln, 'UTF-8') > $max
        ? mb_substr($ln, 0, $max - 1, 'UTF-8') . '…'
        : $ln;
    }
  }
  return '';
}
