<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

function out(array $x, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($x, JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'POST richiesto'], 405);
if (auth_user() === null)                  out(['ok' => false, 'error' => 'non autenticato'], 401);
if (!csrf_check())                         out(['ok' => false, 'error' => 'CSRF non valido'], 403);

$action = (string)($_POST['action'] ?? '');
$me = current_user();
$db = db_rw();

if ($action === 'add') {
  $entry_raw = trim((string)($_POST['entry_id'] ?? ''));
  $note = trim((string)($_POST['note'] ?? ''));
  if ($entry_raw === '' || !ctype_digit($entry_raw) || $note === '') {
    out(['ok' => false, 'error' => 'entry_id/note mancanti o non validi'], 400);
  }
  $entry_id = (int)$entry_raw;
  if (!$db->querySingle('SELECT 1 FROM entries WHERE id=' . $entry_id)) {
    out(['ok' => false, 'error' => 'voce non trovata'], 404);
  }
  $st = $db->prepare('INSERT INTO notes(entry_id, note, author) VALUES(:e,:n,:a)');
  $st->bindValue(':e', $entry_id, SQLITE3_INTEGER);
  $st->bindValue(':n', $note, SQLITE3_TEXT);
  $st->bindValue(':a', $me, SQLITE3_TEXT);
  $st->execute();
  out(['ok' => true, 'id' => (int)$db->lastInsertRowID()]);
}

if ($action === 'edit') {
  $id = (int)($_POST['id'] ?? 0);
  $note = trim((string)($_POST['note'] ?? ''));
  if ($id <= 0 || $note === '') out(['ok' => false, 'error' => 'id/note non validi'], 400);
  if (!$db->querySingle('SELECT 1 FROM notes WHERE id=' . $id)) {
    out(['ok' => false, 'error' => 'nota non trovata'], 404);
  }
  $st = $db->prepare("UPDATE notes SET note=:n, updated_at=datetime('now') WHERE id=:id");
  $st->bindValue(':n', $note, SQLITE3_TEXT);
  $st->bindValue(':id', $id, SQLITE3_INTEGER);
  $st->execute();
  out(['ok' => true]);
}

if ($action === 'delete') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) out(['ok' => false, 'error' => 'id non valido'], 400);
  if (!$db->querySingle('SELECT 1 FROM notes WHERE id=' . $id)) {
    out(['ok' => false, 'error' => 'nota non trovata'], 404);
  }
  $db->exec('DELETE FROM notes WHERE id=' . $id);
  out(['ok' => true]);
}

out(['ok' => false, 'error' => 'action non valida'], 400);
