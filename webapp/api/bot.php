<?php
declare(strict_types=1);

/**
 * API di comando per il bot Telegram: lettura e modifica dell'intera
 * piattaforma. POST JSON, stessa guardia di ingest.php (IP locale + bearer).
 * Ogni richiesta porta `from_id` (utente Telegram) per l'autorizzazione e per
 * attribuire note/modifiche all'autore.
 *
 *   { "cmd": "...", "from_id": 123, ...args }
 *
 * La creazione di voci resta su ingest.php (gestisce anche gli allegati);
 * qui: recent, search, entry, day, random, tags, tag, stats, saved_*,
 *      update, set, delete, note_add, note_del, tag_add, tag_del, sw_add,
 *      rebuild, whoami.
 */

require __DIR__ . '/_guard.php';
require __DIR__ . '/../lib_nlp.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  api_json(['ok' => false, 'error' => 'POST richiesto'], 405);
}
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) api_json(['ok' => false, 'error' => 'JSON non valido'], 400);

$db = db_rw();
$from_id = (int)($in['from_id'] ?? 0);
$user = api_sender($db, $from_id);
if ($user === null) {
  api_json(['ok' => false, 'error' => 'mittente non autorizzato', 'your_id' => $from_id], 403);
}
$cmd = (string)($in['cmd'] ?? '');

/* ----------------------------- helper ----------------------------- */

function b_label(array $x): string {
  $t = trim((string)($x['title'] ?? ''));
  if ($t === '') $t = first_line((string)($x['body'] ?? ''), 70);
  return $t !== '' ? $t : ('voce ' . (int)($x['id'] ?? 0));
}

function b_row(array $x): array {
  return [
    'id'      => (int)$x['id'],
    'slug'    => (string)$x['slug'],
    'label'   => b_label($x),
    'created' => fmt_dt((string)$x['created_at']),
    'day'     => fmt_day(local_ymd((string)$x['created_at'])),
    'source'  => (string)($x['source'] ?? ''),
    'pinned'  => (int)($x['pinned'] ?? 0) === 1,
    'archived' => (int)($x['archived'] ?? 0) === 1,
    'wc'      => (int)($x['word_count'] ?? 0),
    'preview' => mb_substr(trim(preg_replace('/\s+/', ' ', (string)($x['body'] ?? '')) ?? ''), 0, 140, 'UTF-8'),
  ];
}

function b_resolve(SQLite3 $db, $ref): int {
  $id = entry_resolve_ref($db, (string)$ref);
  if ($id === null) api_json(['ok' => false, 'error' => 'voce non trovata: ' . $ref], 404);
  return $id;
}

function b_entry_full(SQLite3 $db, int $eid): array {
  $e = $db->querySingle('SELECT * FROM entries WHERE id = ' . $eid, true);
  if (!$e) api_json(['ok' => false, 'error' => 'voce non trovata'], 404);

  $kw = [];
  $r = $db->query('SELECT term, freq FROM entry_keywords WHERE entry_id = ' . $eid . ' ORDER BY rank');
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $kw[] = ['term' => $x['term'], 'freq' => (int)$x['freq']];

  $tm = $ta = [];
  $r = $db->query('SELECT t.name, et.auto FROM entry_tags et JOIN tags t ON t.id = et.tag_id
                   WHERE et.entry_id = ' . $eid . ' ORDER BY et.auto, t.name');
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
    if ((int)$x['auto'] === 1) $ta[] = (string)$x['name']; else $tm[] = (string)$x['name'];
  }

  // correlati (archi non manuali, doppia direzione, dedup sull'altra voce)
  $rel = [];
  $st = $db->prepare("SELECT CASE WHEN src_id=:id THEN dst_id ELSE src_id END o, kind, score
                      FROM links WHERE (src_id=:id OR dst_id=:id) AND kind<>'manual'");
  $st->bindValue(':id', $eid, SQLITE3_INTEGER);
  $rr = $st->execute();
  while ($x = $rr->fetchArray(SQLITE3_ASSOC)) {
    $o = (int)$x['o'];
    if (!isset($rel[$o]) || $x['score'] > $rel[$o]['score']) {
      $rel[$o] = ['kind' => (string)$x['kind'], 'score' => (float)$x['score']];
    }
  }
  $m_out = $m_in = [];
  $st = $db->prepare("SELECT dst_id FROM links WHERE src_id=:id AND kind='manual'");
  $st->bindValue(':id', $eid, SQLITE3_INTEGER); $rr = $st->execute();
  while ($x = $rr->fetchArray(SQLITE3_ASSOC)) $m_out[] = (int)$x['dst_id'];
  $st = $db->prepare("SELECT src_id FROM links WHERE dst_id=:id AND kind='manual'");
  $st->bindValue(':id', $eid, SQLITE3_INTEGER); $rr = $st->execute();
  while ($x = $rr->fetchArray(SQLITE3_ASSOC)) $m_in[] = (int)$x['src_id'];

  $ids = array_values(array_unique(array_merge(array_keys($rel), $m_out, $m_in)));
  $brief = [];
  if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT id, slug, title, body FROM entries WHERE id IN ($ph)");
    $i = 1; foreach ($ids as $x) $st->bindValue($i++, $x, SQLITE3_INTEGER);
    $rr = $st->execute();
    while ($x = $rr->fetchArray(SQLITE3_ASSOC)) {
      $brief[(int)$x['id']] = ['id' => (int)$x['id'], 'slug' => $x['slug'], 'label' => b_label($x)];
    }
  }
  uasort($rel, static fn($a, $b) => $b['score'] <=> $a['score']);
  $related = [];
  foreach ($rel as $oid => $meta) {
    if (!isset($brief[$oid])) continue;
    $related[] = $brief[$oid] + ['kind' => $meta['kind'], 'score' => (int)round($meta['score'])];
  }
  $map = static fn($arr) => array_values(array_filter(array_map(static fn($x) => $brief[$x] ?? null, $arr)));

  $notes = [];
  $r = $db->query('SELECT id, note, author, created_at FROM notes WHERE entry_id = ' . $eid . ' ORDER BY created_at DESC');
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
    $notes[] = ['id' => (int)$x['id'], 'note' => (string)$x['note'], 'author' => (string)$x['author'],
                'created' => fmt_dt((string)$x['created_at'])];
  }
  $att = [];
  $r = $db->query('SELECT id, kind, orig_name, bytes FROM attachments WHERE entry_id = ' . $eid . ' ORDER BY id');
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
    $att[] = ['id' => (int)$x['id'], 'kind' => (string)$x['kind'],
              'name' => (string)($x['orig_name'] ?: $x['kind']), 'bytes' => (int)$x['bytes']];
  }

  // navigazione cronologica (non archiviate)
  $prev = $db->querySingle("SELECT slug FROM entries WHERE archived=0 AND created_at < '"
      . SQLite3::escapeString((string)$e['created_at']) . "' ORDER BY created_at DESC LIMIT 1");
  $next = $db->querySingle("SELECT slug FROM entries WHERE archived=0 AND created_at > '"
      . SQLite3::escapeString((string)$e['created_at']) . "' ORDER BY created_at ASC LIMIT 1");

  return [
    'entry' => [
      'id' => (int)$e['id'], 'slug' => (string)$e['slug'],
      'title' => (string)($e['title'] ?? ''), 'label' => b_label($e),
      'body' => (string)$e['body'], 'raw' => (string)$e['raw'],
      'created' => fmt_dt((string)$e['created_at']),
      'updated' => $e['updated_at'] ? fmt_dt((string)$e['updated_at']) : '',
      'source' => (string)$e['source'], 'lang' => (string)($e['lang'] ?? ''),
      'wc' => (int)$e['word_count'], 'pinned' => (int)$e['pinned'] === 1,
      'archived' => (int)$e['archived'] === 1,
    ],
    'keywords' => $kw,
    'tags' => ['manual' => $tm, 'auto' => $ta],
    'related' => $related,
    'mentions_out' => $map($m_out),
    'mentions_in' => $map($m_in),
    'notes' => $notes,
    'attachments' => $att,
    'nav' => ['prev' => $prev ?: null, 'next' => $next ?: null],
  ];
}

function b_list(SQLite3 $db, string $sql, array $bind = []): array {
  $st = $db->prepare($sql);
  foreach ($bind as $k => [$v, $t]) $st->bindValue($k, $v, $t);
  $out = [];
  $r = $st->execute();
  while ($x = $r->fetchArray(SQLITE3_ASSOC)) $out[] = b_row($x);
  return $out;
}

/* ----------------------------- dispatch ----------------------------- */

try {
  switch ($cmd) {

  case 'whoami':
    api_json(['ok' => true, 'username' => $user, 'from_id' => $from_id]);

  case 'recent': {
    $n = max(1, min(30, (int)($in['n'] ?? 10)));
    api_json(['ok' => true, 'items' => b_list($db,
      'SELECT * FROM entries WHERE archived = 0 ORDER BY created_at DESC LIMIT ' . $n)]);
  }

  case 'search': {
    $q = trim((string)($in['q'] ?? ''));
    if ($q === '') api_json(['ok' => false, 'error' => 'query vuota'], 400);
    $per = max(1, min(20, (int)($in['per'] ?? 6)));
    $page = max(1, (int)($in['page'] ?? 1));
    $cond = ['entries_fts MATCH :q', 'e.archived = 0'];
    $bind = [':q' => [$q, SQLITE3_TEXT]];
    $tag = nlp_tag_normalize((string)($in['tag'] ?? ''));
    if ($tag !== '') {
      $cond[] = 'e.id IN (SELECT et.entry_id FROM entry_tags et JOIN tags t ON t.id=et.tag_id WHERE t.name=:tag)';
      $bind[':tag'] = [$tag, SQLITE3_TEXT];
    }
    $w = implode(' AND ', $cond);
    try {
      $cs = $db->prepare("SELECT COUNT(*) c FROM entries_fts JOIN entries e ON e.id=entries_fts.rowid WHERE $w");
      foreach ($bind as $k => [$v, $t]) $cs->bindValue($k, $v, $t);
      $total = (int)$cs->execute()->fetchArray(SQLITE3_ASSOC)['c'];
      $pages = max(1, (int)ceil($total / $per));
      if ($page > $pages) $page = $pages;
      $sql = "SELECT e.* FROM entries_fts JOIN entries e ON e.id=entries_fts.rowid
              WHERE $w ORDER BY bm25(entries_fts) LIMIT :lim OFFSET :off";
      $bind[':lim'] = [$per, SQLITE3_INTEGER];
      $bind[':off'] = [($page - 1) * $per, SQLITE3_INTEGER];
      api_json(['ok' => true, 'q' => $q, 'total' => $total, 'page' => $page, 'pages' => $pages,
                'items' => b_list($db, $sql, $bind)]);
    } catch (Throwable $e) {
      api_json(['ok' => false, 'error' => 'query FTS non valida: ' . $e->getMessage()], 400);
    }
  }

  case 'entry':
    api_json(['ok' => true] + b_entry_full($db, b_resolve($db, $in['ref'] ?? '')));

  case 'random': {
    $id = (int)$db->querySingle('SELECT id FROM entries WHERE archived=0 ORDER BY RANDOM() LIMIT 1');
    if ($id <= 0) api_json(['ok' => false, 'error' => 'nessuna voce'], 404);
    api_json(['ok' => true] + b_entry_full($db, $id));
  }

  case 'day': {
    $day = (string)($in['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
      $day = (new DateTime('now', tzobj()))->format('Y-m-d');
    }
    $s = (new DateTime($day . ' 00:00:00', tzobj()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $e = (new DateTime($day . ' 23:59:59', tzobj()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    api_json(['ok' => true, 'date' => $day, 'items' => b_list($db,
      'SELECT * FROM entries WHERE created_at BETWEEN :s AND :e ORDER BY created_at DESC',
      [':s' => [$s, SQLITE3_TEXT], ':e' => [$e, SQLITE3_TEXT]])]);
  }

  case 'tags': {
    $limit = max(1, min(60, (int)($in['limit'] ?? 30)));
    $rows = [];
    $r = $db->query("SELECT t.name, t.kind, COUNT(DISTINCT et.entry_id) c
                     FROM tags t JOIN entry_tags et ON et.tag_id=t.id
                     GROUP BY t.id HAVING c > 0 ORDER BY c DESC, t.name LIMIT $limit");
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
      $rows[] = ['name' => (string)$x['name'], 'kind' => (string)$x['kind'], 'count' => (int)$x['c']];
    }
    api_json(['ok' => true, 'tags' => $rows]);
  }

  case 'tag': {
    $name = nlp_tag_normalize((string)($in['name'] ?? ''));
    if ($name === '') api_json(['ok' => false, 'error' => 'nome tag mancante'], 400);
    $tid = (int)$db->querySingle("SELECT id FROM tags WHERE name='" . SQLite3::escapeString($name) . "'");
    if ($tid <= 0) api_json(['ok' => false, 'error' => 'tag inesistente'], 404);
    $per = max(1, min(20, (int)($in['per'] ?? 8)));
    $page = max(1, (int)($in['page'] ?? 1));
    $total = (int)$db->querySingle("SELECT COUNT(*) FROM entry_tags WHERE tag_id=$tid");
    $pages = max(1, (int)ceil($total / $per));
    if ($page > $pages) $page = $pages;
    $items = b_list($db,
      "SELECT e.* FROM entry_tags et JOIN entries e ON e.id=et.entry_id
       WHERE et.tag_id=$tid ORDER BY e.created_at DESC LIMIT :l OFFSET :o",
      [':l' => [$per, SQLITE3_INTEGER], ':o' => [($page - 1) * $per, SQLITE3_INTEGER]]);
    $cooc = [];
    $r = $db->query("SELECT t2.name, COUNT(*) c FROM entry_tags e1
       JOIN entry_tags e2 ON e2.entry_id=e1.entry_id AND e2.tag_id<>e1.tag_id
       JOIN tags t2 ON t2.id=e2.tag_id WHERE e1.tag_id=$tid
       GROUP BY t2.name ORDER BY c DESC LIMIT 12");
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $cooc[] = ['name' => $x['name'], 'count' => (int)$x['c']];
    api_json(['ok' => true, 'name' => $name, 'total' => $total, 'page' => $page, 'pages' => $pages,
              'items' => $items, 'cooc' => $cooc]);
  }

  case 'stats': {
    $q1 = static fn($s) => (int)$db->querySingle($s);
    $entries = $q1('SELECT COUNT(*) FROM entries WHERE archived=0');
    $words = $q1('SELECT COALESCE(SUM(word_count),0) FROM entries WHERE archived=0');
    $days = [];
    $r = $db->query('SELECT created_at FROM entries WHERE archived=0');
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
      try { $d = (new DateTime((string)$x['created_at'], new DateTimeZone('UTC')))->setTimezone(tzobj())->format('Y-m-d'); }
      catch (Throwable $e) { continue; }
      $days[$d] = true;
    }
    $dk = array_keys($days); sort($dk);
    $today = (new DateTime('now', tzobj()))->format('Y-m-d');
    $yest = (new DateTime('now', tzobj()))->modify('-1 day')->format('Y-m-d');
    $streak = 0;
    if ($dk) {
      $last = end($dk);
      if ($last === $today || $last === $yest) {
        $streak = 1; $c = $last;
        while (isset($days[(new DateTime($c))->modify('-1 day')->format('Y-m-d')])) {
          $c = (new DateTime($c))->modify('-1 day')->format('Y-m-d'); $streak++;
        }
      }
    }
    $top = [];
    $r = $db->query("SELECT t.name, COUNT(DISTINCT et.entry_id) c FROM tags t
       JOIN entry_tags et ON et.tag_id=t.id WHERE t.kind='manual'
       GROUP BY t.id ORDER BY c DESC LIMIT 8");
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $top[] = ['name' => $x['name'], 'count' => (int)$x['c']];
    $hubs = [];
    $r = $db->query("SELECT e.slug, e.title, e.body, COUNT(*) deg FROM
       (SELECT src_id id FROM links UNION ALL SELECT dst_id FROM links) l
       JOIN entries e ON e.id=l.id WHERE e.archived=0
       GROUP BY e.id ORDER BY deg DESC LIMIT 6");
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
      $hubs[] = ['slug' => $x['slug'], 'label' => b_label($x), 'deg' => (int)$x['deg']];
    }
    api_json(['ok' => true,
      'entries' => $entries, 'words' => $words,
      'avg' => $entries ? round($words / $entries, 1) : 0,
      'active_days' => count($dk), 'streak' => $streak,
      'links' => $q1('SELECT COUNT(*) FROM links'),
      'notes' => $q1('SELECT COUNT(*) FROM notes'),
      'tags_m' => $q1("SELECT COUNT(*) FROM tags WHERE kind='manual'"),
      'tags_a' => $q1("SELECT COUNT(*) FROM tags WHERE kind='auto'"),
      'orphans' => $q1('SELECT COUNT(*) FROM entries WHERE archived=0 AND id NOT IN (SELECT src_id FROM links UNION SELECT dst_id FROM links)'),
      'top_tags' => $top, 'hubs' => $hubs,
    ]);
  }

  case 'saved_list': {
    $rows = [];
    $st = $db->prepare('SELECT id, name, q FROM saved_searches WHERE owner=:o ORDER BY name COLLATE NOCASE');
    $st->bindValue(':o', $user, SQLITE3_TEXT);
    $r = $st->execute();
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = ['id' => (int)$x['id'], 'name' => $x['name'], 'q' => $x['q']];
    api_json(['ok' => true, 'items' => $rows]);
  }

  case 'saved_add': {
    $name = trim((string)($in['name'] ?? ''));
    $q = trim((string)($in['q'] ?? ''));
    if ($name === '' || $q === '') api_json(['ok' => false, 'error' => 'nome e query obbligatori'], 400);
    $st = $db->prepare("INSERT INTO saved_searches(owner,name,q) VALUES(:o,:n,:q)
      ON CONFLICT(owner,name) DO UPDATE SET q=excluded.q, created_at=datetime('now')");
    $st->bindValue(':o', $user, SQLITE3_TEXT);
    $st->bindValue(':n', $name, SQLITE3_TEXT);
    $st->bindValue(':q', $q, SQLITE3_TEXT);
    $st->execute();
    api_json(['ok' => true]);
  }

  case 'saved_del': {
    $st = $db->prepare('DELETE FROM saved_searches WHERE id=:i AND owner=:o');
    $st->bindValue(':i', (int)($in['id'] ?? 0), SQLITE3_INTEGER);
    $st->bindValue(':o', $user, SQLITE3_TEXT);
    $st->execute();
    api_json(['ok' => true]);
  }

  case 'update': {
    $id = b_resolve($db, $in['ref'] ?? '');
    $raw = (string)($in['raw'] ?? '');
    $res = entry_update($db, $id, $raw, $user);
    api_json(['ok' => true] + b_entry_full($db, $res['id']));
  }

  case 'set': {
    $id = b_resolve($db, $in['ref'] ?? '');
    $changed = [];
    foreach (['pinned', 'archived'] as $f) {
      if (array_key_exists($f, $in)) {
        $v = (int)!!$in[$f];
        $db->exec("UPDATE entries SET $f=$v WHERE id=$id");
        $changed[$f] = $v;
      }
    }
    api_json(['ok' => true, 'changed' => $changed] + b_entry_full($db, $id));
  }

  case 'delete': {
    $id = b_resolve($db, $in['ref'] ?? '');
    $res = entry_delete($db, $id);     // rimuove anche i file degli allegati
    api_json(['ok' => true, 'deleted' => $res['label'], 'files' => $res['files']]);
  }

  case 'note_add': {
    $id = b_resolve($db, $in['ref'] ?? '');
    $text = trim((string)($in['text'] ?? ''));
    if ($text === '') api_json(['ok' => false, 'error' => 'nota vuota'], 400);
    $st = $db->prepare('INSERT INTO notes(entry_id, note, author) VALUES(:e,:n,:a)');
    $st->bindValue(':e', $id, SQLITE3_INTEGER);
    $st->bindValue(':n', $text, SQLITE3_TEXT);
    $st->bindValue(':a', $user, SQLITE3_TEXT);
    $st->execute();
    api_json(['ok' => true] + b_entry_full($db, $id));
  }

  case 'note_del': {
    $nid = (int)($in['id'] ?? 0);
    $eid = (int)$db->querySingle('SELECT entry_id FROM notes WHERE id=' . $nid);
    if ($eid <= 0) api_json(['ok' => false, 'error' => 'nota inesistente'], 404);
    $db->exec('DELETE FROM notes WHERE id=' . $nid);
    api_json(['ok' => true] + b_entry_full($db, $eid));
  }

  case 'tag_add': {
    $id = b_resolve($db, $in['ref'] ?? '');
    $name = nlp_tag_normalize((string)($in['name'] ?? ''));
    if ($name === '') api_json(['ok' => false, 'error' => 'nome tag non valido'], 400);
    $raw = (string)$db->querySingle('SELECT raw FROM entries WHERE id=' . $id);
    entry_update($db, $id, nlp_merge_tags_into_raw($raw, [$name]), $user);
    api_json(['ok' => true] + b_entry_full($db, $id));
  }

  case 'tag_del': {
    $id = b_resolve($db, $in['ref'] ?? '');
    $name = nlp_tag_normalize((string)($in['name'] ?? ''));
    if ($name === '') api_json(['ok' => false, 'error' => 'nome tag non valido'], 400);
    $raw = (string)$db->querySingle('SELECT raw FROM entries WHERE id=' . $id);
    entry_update($db, $id, nlp_strip_tag_from_raw($raw, $name), $user);
    // rimuove anche un'eventuale associazione non derivata dal testo
    $db->exec("DELETE FROM entry_tags WHERE entry_id=$id AND tag_id=
               (SELECT id FROM tags WHERE name='" . SQLite3::escapeString($name) . "')");
    api_json(['ok' => true] + b_entry_full($db, $id));
  }

  case 'sw_add': {
    $w = mb_strtolower(trim((string)($in['word'] ?? '')), 'UTF-8');
    $w = preg_replace('/[^\p{L}\p{N}\-_]/u', '', $w) ?? '';
    if ($w === '') api_json(['ok' => false, 'error' => 'parola non valida'], 400);
    $st = $db->prepare('INSERT OR IGNORE INTO stopwords_custom(word) VALUES(:w)');
    $st->bindValue(':w', $w, SQLITE3_TEXT);
    $st->execute();
    api_json(['ok' => true, 'word' => $w]);
  }

  case 'rebuild': {
    $res = graph_rebuild($db);
    api_json(['ok' => true] + $res);
  }

  default:
    api_json(['ok' => false, 'error' => 'comando sconosciuto: ' . $cmd], 400);
  }
} catch (Throwable $e) {
  api_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
