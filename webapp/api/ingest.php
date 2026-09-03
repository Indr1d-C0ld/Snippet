<?php
declare(strict_types=1);

/**
 * Ingest di una voce dal bot Telegram (o da altri client locali fidati).
 *
 * POST, solo da IP ammesso + `Authorization: Bearer <ingest_token>`.
 * Corpo:
 *   - application/json: { text, from_id, chat_id, message_id, update_id,
 *                         date (unix), source?, attachments? }
 *   - multipart/form-data: campo `payload` = stesso JSON, piu' file `file0`,
 *     `file1`, ... nell'ordine dell'array `attachments` del payload
 *     (ogni voce: {kind, orig_name, mime, tg_file_id}).
 *
 * Tutta la pipeline (direttive, keyword, tag, correlazioni) e' quella
 * condivisa in lib_nlp.php via entry_save().
 */

require __DIR__ . '/_guard.php';
require __DIR__ . '/../lib_nlp.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  api_json(['ok' => false, 'error' => 'POST richiesto'], 405);
}

$C = cfg();
$ctype = (string)($_SERVER['CONTENT_TYPE'] ?? '');
if (stripos($ctype, 'multipart/form-data') !== false) {
  $payload = json_decode((string)($_POST['payload'] ?? ''), true);
} else {
  $payload = json_decode((string)file_get_contents('php://input'), true);
}
if (!is_array($payload)) {
  api_json(['ok' => false, 'error' => 'payload JSON assente o non valido'], 400);
}

$text    = trim((string)($payload['text'] ?? ''));
$from_id = isset($payload['from_id']) ? (int)$payload['from_id'] : 0;
$chat_id = isset($payload['chat_id']) ? (int)$payload['chat_id'] : null;
$msg_id  = isset($payload['message_id']) ? (int)$payload['message_id'] : null;
$upd_id  = isset($payload['update_id']) ? (int)$payload['update_id'] : null;
$date_ux = isset($payload['date']) ? (int)$payload['date'] : 0;
$source  = (string)($payload['source'] ?? 'telegram');
if (!in_array($source, ['telegram', 'web', 'import'], true)) $source = 'telegram';
$atts_meta = is_array($payload['attachments'] ?? null) ? array_values($payload['attachments']) : [];

$db = db_rw();

/* ---- log grezzo (sempre) ---- */
$log = $db->prepare(
  'INSERT INTO ingest_log(tg_update_id, tg_message_id, chat_id, from_id, raw_json, status)
   VALUES(:u, :m, :c, :f, :r, :s)'
);
$log->bindValue(':u', $upd_id, $upd_id !== null ? SQLITE3_INTEGER : SQLITE3_NULL);
$log->bindValue(':m', $msg_id, $msg_id !== null ? SQLITE3_INTEGER : SQLITE3_NULL);
$log->bindValue(':c', $chat_id, $chat_id !== null ? SQLITE3_INTEGER : SQLITE3_NULL);
$log->bindValue(':f', $from_id ?: null, $from_id ? SQLITE3_INTEGER : SQLITE3_NULL);
$log->bindValue(':r', json_encode($payload, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
$log->bindValue(':s', 'received', SQLITE3_TEXT);
$log->execute();
$log_id = (int)$db->lastInsertRowID();

function ingest_status(SQLite3 $db, int $id, string $s, ?int $eid = null): void {
  $st = $db->prepare('UPDATE ingest_log SET status = :s, entry_id = :e WHERE id = :i');
  $st->bindValue(':s', mb_substr($s, 0, 120, 'UTF-8'), SQLITE3_TEXT);
  $st->bindValue(':e', $eid, $eid !== null ? SQLITE3_INTEGER : SQLITE3_NULL);
  $st->bindValue(':i', $id, SQLITE3_INTEGER);
  $st->execute();
}

/* ---- whitelist mittente ---- */
$username = null;
if ($from_id > 0) {
  $st = $db->prepare('SELECT username FROM tg_allowed WHERE tg_user_id = :i');
  $st->bindValue(':i', $from_id, SQLITE3_INTEGER);
  $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
  if ($row) $username = (string)$row['username'];
}
if ($username === null
    && $from_id > 0
    && in_array($from_id, array_map('intval', (array)($C['ingest_bootstrap_from'] ?? [])), true)) {
  $u0 = (string)$db->querySingle('SELECT username FROM users ORDER BY id LIMIT 1');
  if ($u0 === '') {
    ingest_status($db, $log_id, 'no-user');
    api_json(['ok' => false, 'error' => 'nessun account: crea prima quello web'], 409);
  }
  $ins = $db->prepare('INSERT OR IGNORE INTO tg_allowed(tg_user_id, username) VALUES(:i, :u)');
  $ins->bindValue(':i', $from_id, SQLITE3_INTEGER);
  $ins->bindValue(':u', $u0, SQLITE3_TEXT);
  $ins->execute();
  $username = $u0;
}
if ($username === null) {
  ingest_status($db, $log_id, 'denied');
  api_json(['ok' => false, 'error' => 'mittente non autorizzato', 'your_id' => $from_id], 403);
}

/* ---- idempotenza sul messaggio Telegram ---- */
if ($chat_id !== null && $msg_id !== null) {
  $st = $db->prepare('SELECT id, slug FROM entries WHERE tg_chat_id = :c AND tg_message_id = :m');
  $st->bindValue(':c', $chat_id, SQLITE3_INTEGER);
  $st->bindValue(':m', $msg_id, SQLITE3_INTEGER);
  $ex = $st->execute()->fetchArray(SQLITE3_ASSOC);
  if ($ex) {
    ingest_status($db, $log_id, 'duplicate', (int)$ex['id']);
    api_json([
      'ok' => true, 'duplicate' => true,
      'id' => (int)$ex['id'], 'slug' => (string)$ex['slug'],
      'url' => 'entry.php?e=' . rawurlencode((string)$ex['slug']),
    ]);
  }
}

$has_files = !empty($_FILES);
if ($text === '' && !$has_files) {
  ingest_status($db, $log_id, 'empty');
  api_json(['ok' => false, 'error' => 'messaggio vuoto'], 400);
}
if ($text === '') $text = '(allegato senza testo)';

$created = $date_ux > 0 ? gmdate('Y-m-d H:i:s', $date_ux) : now_utc();

/* ---- salva allegati caricati ---- */
function ingest_store_attachments(SQLite3 $db, int $eid, array $meta, int $max_bytes): array {
  $C = cfg();
  $base = rtrim((string)$C['attachments_dir'], '/');
  $dir  = $base . '/' . $eid;
  $out  = [];

  $keys = array_keys($_FILES);
  natsort($keys);
  $i = 0;
  foreach ($keys as $k) {
    $f = $_FILES[$k];
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $i++; continue; }
    if (($f['size'] ?? 0) <= 0 || ($f['size'] ?? 0) > $max_bytes) { $i++; continue; }

    $m = $meta[$i] ?? [];
    $kind = (string)($m['kind'] ?? 'document');
    if (!in_array($kind, ['photo', 'voice', 'audio', 'document', 'video'], true)) $kind = 'document';
    $orig = (string)($m['orig_name'] ?? $f['name'] ?? ('file' . $i));
    $mime = (string)($m['mime'] ?? $f['type'] ?? 'application/octet-stream');

    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
    $ext = substr($ext, 0, 8);

    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fname = bin2hex(random_bytes(8)) . '.' . $ext;
    $dest  = $dir . '/' . $fname;
    if (!move_uploaded_file($f['tmp_name'], $dest)) { $i++; continue; }

    $st = $db->prepare(
      'INSERT INTO attachments(entry_id, kind, path, mime, bytes, orig_name, tg_file_id)
       VALUES(:e, :k, :p, :mi, :b, :o, :t)'
    );
    $st->bindValue(':e', $eid, SQLITE3_INTEGER);
    $st->bindValue(':k', $kind, SQLITE3_TEXT);
    $st->bindValue(':p', $eid . '/' . $fname, SQLITE3_TEXT);
    $st->bindValue(':mi', $mime, SQLITE3_TEXT);
    $st->bindValue(':b', (int)$f['size'], SQLITE3_INTEGER);
    $st->bindValue(':o', $orig, SQLITE3_TEXT);
    $st->bindValue(':t', (string)($m['tg_file_id'] ?? ''), SQLITE3_TEXT);
    $st->execute();

    $out[] = ['kind' => $kind, 'orig_name' => $orig, 'bytes' => (int)$f['size']];
    $i++;
  }
  return $out;
}

try {
  $db->exec('BEGIN');
  $res = entry_save($db, [
    'raw'           => $text,
    'author'        => $username,
    'source'        => $source,
    'created_at'    => $created,
    'tg_chat_id'    => $chat_id,
    'tg_message_id' => $msg_id,
    'tg_from_id'    => $from_id,
  ]);
  $atts = $has_files
    ? ingest_store_attachments($db, $res['id'], $atts_meta, (int)($C['attach_max_bytes'] ?? 20 * 1024 * 1024))
    : [];
  $db->exec('COMMIT');

  ingest_status($db, $log_id, 'ok', $res['id']);
  api_json([
    'ok'   => true,
    'id'   => $res['id'],
    'slug' => $res['slug'],
    'url'  => 'entry.php?e=' . rawurlencode($res['slug']),
    'attachments' => $atts,
  ]);
} catch (Throwable $e) {
  $db->exec('ROLLBACK');
  ingest_status($db, $log_id, 'error: ' . $e->getMessage());
  api_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
