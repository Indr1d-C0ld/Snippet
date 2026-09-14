<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); die('id mancante'); }

$db = db_ro();
$st = $db->prepare('SELECT entry_id, kind, path, mime, orig_name FROM attachments WHERE id = :i');
$st->bindValue(':i', $id, SQLITE3_INTEGER);
$a = $st->execute()->fetchArray(SQLITE3_ASSOC);
if (!$a) { http_response_code(404); die('allegato non trovato'); }

$base = realpath((string)cfg()['attachments_dir']);
$full = $base !== false ? realpath($base . '/' . (string)$a['path']) : false;
if ($base === false || $full === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
  http_response_code(404); die('file non disponibile');
}

/**
 * Il MIME arriva dal client di ingest (Telegram) ed e' quindi dato non fidato:
 * non lo si rimanda mai tale e quale. Solo i tipi in whitelist vengono serviti
 * con il loro Content-Type e mostrati inline; tutto il resto diventa un
 * download opaco. In particolare image/svg+xml NON e' in whitelist: un SVG
 * servito inline sulla stessa origine puo' eseguire script (la CSP del portale
 * consente 'unsafe-inline').
 */
const ATT_INLINE_MIME = [
  'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/heic',
  'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/wav', 'audio/webm',
  'video/mp4', 'video/webm', 'video/quicktime',
  'application/pdf',
];

$declared = strtolower(trim(explode(';', (string)($a['mime'] ?: ''))[0]));
$inline   = in_array($declared, ATT_INLINE_MIME, true);
$mime     = $inline ? $declared : 'application/octet-stream';
$name = preg_replace('/[^\w.\- ]+/u', '_', (string)($a['orig_name'] ?: ('allegato-' . $id)));

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($full));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Cache-Control: private, max-age=86400');
readfile($full);
