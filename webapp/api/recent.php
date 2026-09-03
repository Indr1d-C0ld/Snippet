<?php
declare(strict_types=1);

/**
 * Elenco voci recenti / ricerca rapida, per i comandi /last /find /tag del bot.
 * GET, stessa guardia di ingest.php (IP locale + bearer token).
 *   ?n=10            ultime N voci (1..30)
 *   ?q=parola         ricerca FTS (usa N come limite)
 *   ?tag=nome         voci con quel tag
 */

require __DIR__ . '/_guard.php';
require __DIR__ . '/../lib_nlp.php';

$db = db_ro();

$n = (int)($_GET['n'] ?? 10);
$n = max(1, min(30, $n));
$q   = trim((string)($_GET['q'] ?? ''));
$tag = nlp_tag_normalize((string)($_GET['tag'] ?? ''));

$rows = [];
try {
  if ($q !== '') {
    $st = $db->prepare("
      SELECT e.id, e.slug, e.title, e.body, e.created_at
      FROM entries_fts JOIN entries e ON e.id = entries_fts.rowid
      WHERE entries_fts MATCH :q AND e.archived = 0
      ORDER BY bm25(entries_fts) LIMIT :n
    ");
    $st->bindValue(':q', $q, SQLITE3_TEXT);
  } elseif ($tag !== '') {
    $st = $db->prepare("
      SELECT e.id, e.slug, e.title, e.body, e.created_at
      FROM entry_tags et JOIN tags t ON t.id = et.tag_id
      JOIN entries e ON e.id = et.entry_id
      WHERE t.name = :tag AND e.archived = 0
      ORDER BY e.created_at DESC LIMIT :n
    ");
    $st->bindValue(':tag', $tag, SQLITE3_TEXT);
  } else {
    $st = $db->prepare("
      SELECT id, slug, title, body, created_at
      FROM entries WHERE archived = 0
      ORDER BY created_at DESC LIMIT :n
    ");
  }
  $st->bindValue(':n', $n, SQLITE3_INTEGER);
  $r = $st->execute();
  while ($x = $r->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = [
      'id'      => (int)$x['id'],
      'slug'    => (string)$x['slug'],
      'title'   => (string)($x['title'] ?: first_line((string)$x['body'], 70)),
      'created' => fmt_dt((string)$x['created_at']),
      'url'     => 'entry.php?e=' . rawurlencode((string)$x['slug']),
    ];
  }
} catch (Throwable $e) {
  api_json(['ok' => false, 'error' => $e->getMessage()], 400);
}

api_json(['ok' => true, 'count' => count($rows), 'items' => $rows]);
