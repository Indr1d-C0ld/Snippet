<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/lib_nlp.php';

if (auth_user() === null) {
  http_response_code(401);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'non autenticato']);
  exit;
}

header('Content-Type: application/json; charset=utf-8');

$C = cfg();
$db = db_ro();

/* -------- parametri -------- */
$tag = nlp_tag_normalize((string)($_GET['tag'] ?? ''));
$from = (string)($_GET['from'] ?? '');
$to   = (string)($_GET['to'] ?? '');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : '';
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : '';
$minscore = isset($_GET['minscore']) && is_numeric($_GET['minscore'])
  ? max(0, (float)$_GET['minscore'])
  : (float)($C['correlate_min_score'] ?? 2);
$archived = (int)($_GET['archived'] ?? 0) === 1;
$limit = isset($_GET['limit']) && ctype_digit((string)$_GET['limit'])
  ? min(1500, max(10, (int)$_GET['limit'])) : 500;

$ALL_KINDS = ['manual', 'keyword', 'tag', 'temporal'];
$kinds = array_values(array_intersect(
  $ALL_KINDS,
  array_filter(array_map('trim', explode(',', (string)($_GET['kinds'] ?? ''))))
));
if (!$kinds) $kinds = $ALL_KINDS;

/* -------- nodi -------- */
$where = [];
$params = [];
if (!$archived) $where[] = 'e.archived = 0';

if ($from !== '') {
  $params[':from'] = [(new DateTime($from . ' 00:00:00', tzobj()))
    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), SQLITE3_TEXT];
  $where[] = 'e.created_at >= :from';
}
if ($to !== '') {
  $params[':to'] = [(new DateTime($to . ' 23:59:59', tzobj()))
    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), SQLITE3_TEXT];
  $where[] = 'e.created_at <= :to';
}

$join = '';
if ($tag !== '') {
  $join = 'JOIN entry_tags et0 ON et0.entry_id = e.id JOIN tags t0 ON t0.id = et0.tag_id';
  $where[] = 't0.name = :tag';
  $params[':tag'] = [$tag, SQLITE3_TEXT];
}

$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$sql = "
  SELECT DISTINCT e.id, e.slug, e.title, e.body, e.created_at,
         e.pinned, e.archived, e.word_count
  FROM entries e $join
  $wsql
  ORDER BY e.created_at DESC
  LIMIT :lim
";
$st = $db->prepare($sql);
foreach ($params as $k => [$v, $t]) $st->bindValue($k, $v, $t);
$st->bindValue(':lim', $limit, SQLITE3_INTEGER);

$nodes = [];
$idset = [];
$r = $st->execute();
while ($x = $r->fetchArray(SQLITE3_ASSOC)) {
  $id = (int)$x['id'];
  $idset[$id] = true;
  $label = (string)($x['title'] ?: first_line((string)$x['body'], 60));
  if ($label === '') $label = 'voce ' . $id;
  $nodes[$id] = [
    'id'     => $id,
    'slug'   => (string)$x['slug'],
    'label'  => $label,
    'day'    => local_ymd((string)$x['created_at']),
    'wc'     => (int)$x['word_count'],
    'pinned' => (int)$x['pinned'] === 1,
    'arch'   => (int)$x['archived'] === 1,
    'deg'    => 0,
    'tags'   => [],
  ];
}

/* -------- tag manuali per i nodi (per le etichette) -------- */
if ($nodes) {
  $tr = $db->query(
    "SELECT et.entry_id, t.name
     FROM entry_tags et JOIN tags t ON t.id = et.tag_id
     WHERE et.auto = 0
     ORDER BY t.name ASC"
  );
  while ($tr && ($x = $tr->fetchArray(SQLITE3_ASSOC))) {
    $eid = (int)$x['entry_id'];
    if (isset($nodes[$eid]) && count($nodes[$eid]['tags']) < 5) {
      $nodes[$eid]['tags'][] = (string)$x['name'];
    }
  }
}

/* -------- archi (links e' piccolo: filtro in PHP l'appartenenza ai nodi) -------- */
$kind_lits = implode(',', array_map(
  static fn($k) => "'" . SQLite3::escapeString($k) . "'", $kinds
));
$edges = [];         // chiave coppia non orientata -> arco migliore
$er = $db->query("SELECT src_id, dst_id, kind, score FROM links WHERE kind IN ($kind_lits)");
while ($er && ($x = $er->fetchArray(SQLITE3_ASSOC))) {
  $s = (int)$x['src_id'];
  $d = (int)$x['dst_id'];
  if ($s === $d || !isset($idset[$s]) || !isset($idset[$d])) continue;
  $kind = (string)$x['kind'];
  $score = (float)$x['score'];
  if ($kind !== 'manual' && $score < $minscore) continue;

  $key = $s < $d ? "$s-$d" : "$d-$s";
  $cur = $edges[$key] ?? null;
  // preferenza: 'manual' vince sempre; altrimenti punteggio piu' alto
  $better = $cur === null
    || ($kind === 'manual' && $cur['kind'] !== 'manual')
    || ($kind !== 'manual' && $cur['kind'] !== 'manual' && $score > $cur['score']);
  if ($better) {
    $edges[$key] = ['s' => $s, 'd' => $d, 'kind' => $kind, 'score' => $score];
  }
}

foreach ($edges as $e) {
  $nodes[$e['s']]['deg']++;
  $nodes[$e['d']]['deg']++;
}

echo json_encode([
  'meta' => [
    'nodes'    => count($nodes),
    'edges'    => count($edges),
    'limit'    => $limit,
    'minscore' => $minscore,
    'kinds'    => $kinds,
    'tag'      => $tag,
  ],
  'nodes' => array_values($nodes),
  'edges' => array_values($edges),
], JSON_UNESCAPED_UNICODE);
