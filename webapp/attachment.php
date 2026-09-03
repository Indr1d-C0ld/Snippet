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

$mime = (string)($a['mime'] ?: 'application/octet-stream');
$inline = (bool)preg_match('~^(image|audio|video)/~', $mime) || $mime === 'application/pdf';
$name = preg_replace('/[^\w.\- ]+/u', '_', (string)($a['orig_name'] ?: ('allegato-' . $id)));

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($full));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Cache-Control: private, max-age=86400');
readfile($full);
