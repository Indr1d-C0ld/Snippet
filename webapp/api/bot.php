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
 *      rebuild, whoami; dal 08/10/2026 anche persons, person, person_add,
 *      person_ignore, themes, theme, semantic, similar, hints, append, by_tg,
 *      transcript, links_fetch, memories, digest, idle, settings_get/set.
 */

require __DIR__ . '/_guard.php';
require __DIR__ . '/../lib_nlp.php';
require __DIR__ . '/../lib_search.php';

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
                      FROM links WHERE (src_id=:id OR dst_id=:id) AND kind NOT IN ('manual','temporal')");
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
    $related[] = $brief[$oid] + ['kind' => link_kind_label($meta['kind']), 'score' => (int)round($meta['score'] * 100)];
  }
  $map = static fn($arr) => array_values(array_filter(array_map(static fn($x) => $brief[$x] ?? null, $arr)));

  $notes = [];
  $r = $db->query('SELECT id, note, author, created_at FROM notes WHERE entry_id = ' . $eid . ' ORDER BY created_at DESC');
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
    $notes[] = ['id' => (int)$x['id'], 'note' => (string)$x['note'], 'author' => (string)$x['author'],
                'created' => fmt_dt((string)$x['created_at'])];
  }
  $att = [];
  $r = $db->query('SELECT id, kind, orig_name, bytes, transcript_status FROM attachments WHERE entry_id = ' . $eid . ' ORDER BY id');
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
    $att[] = ['id' => (int)$x['id'], 'kind' => (string)$x['kind'],
              'name' => (string)($x['orig_name'] ?: $x['kind']), 'bytes' => (int)$x['bytes'],
              'transcript' => (string)($x['transcript_status'] ?? '')];
  }
  $links = [];
  foreach (links_for_entry($db, $eid) as $l) {
    $links[] = ['url' => (string)$l['url'], 'status' => (string)$l['status'],
                'title' => (string)($l['title'] ?? ''), 'site' => (string)($l['site'] ?? '')];
  }

  // navigazione cronologica (non archiviate)
  $prev = $db->querySingle("SELECT slug FROM entries WHERE archived=0 AND created_at < '"
      . SQLite3::escapeString((string)$e['created_at']) . "' ORDER BY created_at DESC LIMIT 1");
  $next = $db->querySingle("SELECT slug FROM entries WHERE archived=0 AND created_at > '"
      . SQLite3::escapeString((string)$e['created_at']) . "' ORDER BY created_at ASC LIMIT 1");

  $persons = [];
  $r = $db->query('SELECT p.id, p.name FROM entry_persons ep JOIN persons p ON p.id = ep.person_id
                   WHERE ep.entry_id = ' . $eid . ' ORDER BY ep.mentions DESC, p.name');
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $persons[] = ['id' => (int)$x['id'], 'name' => (string)$x['name']];
  $theme = $e['cluster'] !== null ? $db->querySingle('SELECT id, label FROM clusters WHERE id = ' . (int)$e['cluster'], true) : null;

  return [
    'persons' => $persons,
    'theme' => $theme ? ['id' => (int)$theme['id'], 'label' => (string)$theme['label']] : null,
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
    'links' => $links,
    'nav' => ['prev' => $prev ?: null, 'next' => $next ?: null],
  ];
}

/** Estremi UTC [inizio, fine] di un giorno locale 'AAAA-MM-GG'. */
function b_day_bounds(string $ymd): array {
  $s = (new DateTime($ymd . ' 00:00:00', tzobj()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  $e = (new DateTime($ymd . ' 23:59:59', tzobj()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  return [$s, $e];
}

/** Impostazioni del bot (tabella kv, prefisso "bot."), con i default. */
const BOT_SETTINGS = [
  'memories'    => '1',        // ricordi: "un mese fa / un anno fa scrivevi..."
  'memories_at' => '09:00',
  'digest'      => '1',        // riepilogo settimanale
  'digest_at'   => '7 20:00',  // giorno ISO (1 = lunedi', 7 = domenica) e ora
  'nudge'       => '1',        // promemoria dopo qualche giorno di silenzio
  'nudge_days'  => '4',
  'nudge_at'    => '21:00',
];

function b_settings(SQLite3 $db): array {
  $out = [];
  foreach (BOT_SETTINGS as $k => $def) $out[$k] = kv_get($db, 'bot.' . $k, $def);
  return $out;
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
    // motore condiviso col web: filtri nel testo (tag: persona: da: a: tema:
    // fonte:) e modalita' mista parole + significato se il servizio ML c'e'
    $q = trim((string)($in['q'] ?? ''));
    if ($q === '') api_json(['ok' => false, 'error' => 'query vuota'], 400);
    $per = max(1, min(20, (int)($in['per'] ?? 6)));
    $page = max(1, (int)($in['page'] ?? 1));
    $res = search_run($db, $q, (string)($in['mode'] ?? 'auto'), $page, $per);
    $pages = max(1, (int)ceil($res['total'] / $per));
    $label = ['misto' => 'parole + significato', 'parole' => 'parole', 'significato' => 'significato', 'filtri' => 'filtri'][$res['mode']] ?? '';
    api_json(['ok' => true, 'q' => $q, 'total' => $res['total'], 'page' => min($page, $pages), 'pages' => $pages,
              'filters' => trim($label . ($res['parsed']['desc'] !== '' ? ' · ' . $res['parsed']['desc'] : '')
                                 . ($res['note'] !== '' ? ' · ' . $res['note'] : '')),
              'items' => array_map('b_row', $res['items'])]);
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

  /* --------------------------- persone --------------------------- */

  case 'persons': {
    $rows = [];
    $r = $db->query('SELECT p.id, p.name, COUNT(ep.entry_id) n, MAX(e.created_at) last FROM persons p
                     LEFT JOIN entry_persons ep ON ep.person_id = p.id LEFT JOIN entries e ON e.id = ep.entry_id
                     GROUP BY p.id ORDER BY n DESC, p.name COLLATE NOCASE');
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
      $rows[] = ['id' => (int)$x['id'], 'name' => (string)$x['name'], 'count' => (int)$x['n'],
                 'last' => $x['last'] ? fmt_dt((string)$x['last'], false) : ''];
    }
    api_json(['ok' => true, 'persons' => $rows]);
  }

  case 'person': {
    $q = trim((string)($in['name'] ?? ''));
    $pid = (int)($in['id'] ?? 0);
    if ($pid <= 0 && $q !== '') {
      $st = $db->prepare("SELECT id FROM persons WHERE name = :n COLLATE NOCASE
                          OR (',' || replace(aliases, ', ', ',') || ',') LIKE '%,' || :n || ',%' COLLATE NOCASE LIMIT 1");
      $st->bindValue(':n', $q, SQLITE3_TEXT);
      $pid = (int)($st->execute()->fetchArray(SQLITE3_NUM)[0] ?? 0);
    }
    $p = $pid > 0 ? $db->querySingle('SELECT id, name, aliases FROM persons WHERE id = ' . $pid, true) : null;
    if (!$p) api_json(['ok' => false, 'error' => 'persona non trovata: ' . $q], 404);
    $per = max(1, min(20, (int)($in['per'] ?? 8)));
    $page = max(1, (int)($in['page'] ?? 1));
    $total = (int)$db->querySingle('SELECT COUNT(*) FROM entry_persons WHERE person_id = ' . $pid);
    $pages = max(1, (int)ceil($total / $per));
    if ($page > $pages) $page = $pages;
    $items = b_list($db, "SELECT e.* FROM entry_persons ep JOIN entries e ON e.id = ep.entry_id
                          WHERE ep.person_id = $pid ORDER BY e.created_at DESC LIMIT :l OFFSET :o",
      [':l' => [$per, SQLITE3_INTEGER], ':o' => [($page - 1) * $per, SQLITE3_INTEGER]]);
    $with = [];
    $r = $db->query("SELECT p2.name, COUNT(*) c FROM entry_persons a JOIN entry_persons b
                     ON b.entry_id = a.entry_id AND b.person_id <> a.person_id JOIN persons p2 ON p2.id = b.person_id
                     WHERE a.person_id = $pid GROUP BY p2.id ORDER BY c DESC LIMIT 6");
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $with[] = (string)$x['name'];
    api_json(['ok' => true, 'id' => $pid, 'name' => (string)$p['name'], 'aliases' => (string)$p['aliases'],
              'total' => $total, 'page' => $page, 'pages' => $pages, 'items' => $items, 'with' => $with]);
  }

  case 'person_add': {
    $name = trim((string)($in['name'] ?? ''));
    $id = person_add($db, $name, (string)($in['aliases'] ?? ''));
    $n = persons_reindex($db);
    graph_rebuild($db);
    api_json(['ok' => true, 'id' => $id, 'name' => $name, 'entries' => $n]);
  }

  case 'person_ignore': {
    person_ignore($db, (string)($in['name'] ?? ''));
    api_json(['ok' => true]);
  }

  /* ----------------------------- temi ----------------------------- */

  case 'themes': {
    $rows = [];
    $r = $db->query("SELECT c.id, c.label, c.size, MAX(e.created_at) last,
                       SUM(e.created_at >= datetime('now', '-14 days')) recent
                     FROM clusters c JOIN entries e ON e.cluster = c.id
                     GROUP BY c.id ORDER BY recent DESC, c.size DESC");
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
      $rows[] = ['id' => (int)$x['id'], 'label' => (string)$x['label'], 'size' => (int)$x['size'],
                 'recent' => (int)$x['recent'], 'last' => fmt_dt((string)$x['last'], false)];
    }
    api_json(['ok' => true, 'themes' => $rows]);
  }

  case 'theme': {
    $tid = (int)($in['id'] ?? 0);
    $t = $db->querySingle('SELECT id, label, size FROM clusters WHERE id = ' . $tid, true);
    if (!$t) api_json(['ok' => false, 'error' => 'tema non trovato (i temi si ricalcolano)'], 404);
    api_json(['ok' => true, 'id' => $tid, 'label' => (string)$t['label'], 'items' => b_list($db,
      "SELECT * FROM entries WHERE cluster = $tid ORDER BY created_at DESC LIMIT 20")]);
  }

  /* -------------------- significato e suggerimenti -------------------- */

  case 'semantic': {
    $q = trim((string)($in['q'] ?? ''));
    if ($q === '') api_json(['ok' => false, 'error' => 'query vuota'], 400);
    $hits = ml_semantic_search($db, $q, (int)($in['n'] ?? 8));
    if ($hits === null) api_json(['ok' => false, 'error' => 'ricerca per significato non disponibile (servizio ML spento)'], 503);
    $items = [];
    foreach ($hits as $id => $cos) {
      $x = $db->querySingle('SELECT * FROM entries WHERE id = ' . (int)$id, true);
      if ($x && (int)$x['archived'] === 0) $items[] = b_row($x);
    }
    api_json(['ok' => true, 'q' => $q, 'items' => $items]);
  }

  case 'similar': {
    $id = b_resolve($db, $in['ref'] ?? '');
    $items = [];
    foreach (entry_semantic_neighbors($db, $id, 8) as $o => $cos) {
      $x = $db->querySingle('SELECT * FROM entries WHERE id = ' . (int)$o, true);
      if ($x) $items[] = b_row($x);
    }
    api_json(['ok' => true, 'items' => $items]);
  }

  /* ------------------------- testo e allegati ------------------------- */

  case 'append': {
    $id = b_resolve($db, $in['ref'] ?? '');
    $res = entry_append($db, $id, (string)($in['text'] ?? ''), $user);
    api_json(['ok' => true, 'hints' => array_intersect_key($res, ['candidates' => 1, 'suggest' => 1])] + b_entry_full($db, $id));
  }

  case 'by_tg': {
    $st = $db->prepare('SELECT slug FROM entries WHERE tg_chat_id = :c AND tg_message_id = :m');
    $st->bindValue(':c', (int)($in['chat_id'] ?? 0), SQLITE3_INTEGER);
    $st->bindValue(':m', (int)($in['message_id'] ?? 0), SQLITE3_INTEGER);
    $slug = $st->execute()->fetchArray(SQLITE3_NUM)[0] ?? null;
    if ($slug === null) api_json(['ok' => false, 'error' => 'nessuna voce per quel messaggio'], 404);
    api_json(['ok' => true, 'slug' => (string)$slug]);
  }

  case 'transcript': {
    // att_id esplicito, oppure il primo vocale/audio in attesa della voce
    $aid = (int)($in['att_id'] ?? 0);
    if ($aid <= 0) {
      $id = b_resolve($db, $in['ref'] ?? '');
      $aid = (int)$db->querySingle("SELECT id FROM attachments WHERE entry_id = $id AND kind IN ('voice','audio')
                                    ORDER BY (transcript_status = 'pending') DESC, id LIMIT 1");
    }
    if ($aid <= 0) api_json(['ok' => false, 'error' => 'nessun vocale da trascrivere'], 404);
    if (!empty($in['error'])) {
      $db->exec("UPDATE attachments SET transcript_status = 'error' WHERE id = " . $aid);
      api_json(['ok' => true, 'marked' => 'error']);
    }
    $db->exec('BEGIN');
    $res = attachment_transcript($db, $aid, (string)($in['text'] ?? ''), $user);
    $db->exec('COMMIT');
    api_json(['ok' => true, 'hints' => array_intersect_key($res, ['candidates' => 1, 'suggest' => 1])] + b_entry_full($db, (int)$res['id']));
  }

  case 'links_fetch': {
    $id = isset($in['ref']) ? b_resolve($db, $in['ref']) : null;
    api_json(['ok' => true] + links_fetch_pending($db, $id));
  }

  /* ------------------- ricordi, digest, promemoria ------------------- */

  case 'memories': {
    $today = new DateTime('now', tzobj());
    $periods = ['una settimana fa' => '-7 days', 'un mese fa' => '-1 month', 'tre mesi fa' => '-3 months',
                'sei mesi fa' => '-6 months', 'un anno fa' => '-1 year'];
    for ($y = 2; $y <= 10; $y++) $periods["$y anni fa"] = "-$y years";
    $out = [];
    foreach ($periods as $label => $mod) {
      $d = (clone $today)->modify($mod)->format('Y-m-d');
      [$a, $b] = b_day_bounds($d);
      $items = b_list($db, 'SELECT * FROM entries WHERE archived = 0 AND created_at BETWEEN :a AND :b ORDER BY created_at',
                      [':a' => [$a, SQLITE3_TEXT], ':b' => [$b, SQLITE3_TEXT]]);
      if ($items) $out[] = ['label' => $label, 'date' => fmt_day($d), 'items' => $items];
    }
    api_json(['ok' => true, 'groups' => $out]);
  }

  case 'digest': {
    $days = max(1, min(31, (int)($in['days'] ?? 7)));
    $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
    $items = b_list($db, 'SELECT * FROM entries WHERE archived = 0 AND created_at >= :s ORDER BY created_at',
                    [':s' => [$since, SQLITE3_TEXT]]);
    $ids = array_column($items, 'id');
    $in_ids = $ids ? implode(',', array_map('intval', $ids)) : '0';
    $words = (int)$db->querySingle("SELECT COALESCE(SUM(word_count),0) FROM entries WHERE id IN ($in_ids)");
    $persons = $themes = $tags = [];
    $r = $db->query("SELECT p.name, COUNT(*) c FROM entry_persons ep JOIN persons p ON p.id = ep.person_id
                     WHERE ep.entry_id IN ($in_ids) GROUP BY p.id ORDER BY c DESC LIMIT 6");
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $persons[] = ['name' => (string)$x['name'], 'count' => (int)$x['c']];
    $r = $db->query("SELECT c.id, c.label, COUNT(*) n, c.size FROM entries e JOIN clusters c ON c.id = e.cluster
                     WHERE e.id IN ($in_ids) GROUP BY c.id ORDER BY n DESC LIMIT 4");
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $themes[] = ['id' => (int)$x['id'], 'label' => (string)$x['label'], 'week' => (int)$x['n'], 'size' => (int)$x['size']];
    $r = $db->query("SELECT t.name, COUNT(*) c FROM entry_tags et JOIN tags t ON t.id = et.tag_id
                     WHERE et.entry_id IN ($in_ids) GROUP BY t.id ORDER BY c DESC, t.name LIMIT 8");
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $tags[] = ['name' => (string)$x['name'], 'count' => (int)$x['c']];
    // dal passato: la voce piu' vecchia della settimana piu' affine a quelle di questa settimana
    $echo = null;
    if ($ids) {
      $x = $db->querySingle("SELECT e.*, MAX(l.score) s FROM links l
             JOIN entries e ON e.id = CASE WHEN l.src_id IN ($in_ids) THEN l.dst_id ELSE l.src_id END
             WHERE (l.src_id IN ($in_ids) OR l.dst_id IN ($in_ids)) AND l.kind NOT IN ('temporal')
               AND e.id NOT IN ($in_ids) AND e.archived = 0
             GROUP BY e.id ORDER BY s DESC LIMIT 1", true);
      if ($x) $echo = b_row($x);
    }
    api_json(['ok' => true, 'days' => $days, 'count' => count($items), 'words' => $words, 'items' => $items,
              'persons' => $persons, 'themes' => $themes, 'tags' => $tags, 'echo' => $echo]);
  }

  case 'idle': {
    $last = $db->querySingle('SELECT MAX(created_at) FROM entries');
    $days = $last ? (int)floor((time() - strtotime($last . ' UTC')) / 86400) : -1;
    api_json(['ok' => true, 'days' => $days, 'last' => $last ? fmt_dt((string)$last) : '']);
  }

  case 'settings_get':
    api_json(['ok' => true, 'settings' => b_settings($db), 'state' => [
      'memories' => kv_get($db, 'bot.sent.memories', ''), 'digest' => kv_get($db, 'bot.sent.digest', ''),
      'nudge' => kv_get($db, 'bot.sent.nudge', '')]]);

  case 'settings_set': {
    foreach ((array)($in['set'] ?? []) as $k => $v) {
      $k = (string)$k; $v = trim((string)$v);
      if (array_key_exists($k, BOT_SETTINGS)) {
        $ok = match ($k) {
          'memories', 'digest', 'nudge' => in_array($v, ['0', '1'], true),
          'memories_at', 'nudge_at' => (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v),
          'digest_at' => (bool)preg_match('/^[1-7] ([01]\d|2[0-3]):[0-5]\d$/', $v),
          'nudge_days' => ctype_digit($v) && (int)$v >= 1 && (int)$v <= 60,
        };
        if (!$ok) api_json(['ok' => false, 'error' => "valore non valido per $k: $v"], 400);
        kv_set($db, 'bot.' . $k, $v);
      } elseif (preg_match('/^sent\.(memories|digest|nudge)$/', $k)) {
        kv_set($db, 'bot.' . $k, $v);       // segnalibri di invio (anti-doppioni dopo un riavvio)
      }
    }
    api_json(['ok' => true, 'settings' => b_settings($db)]);
  }

  case 'hints': {
    $id = b_resolve($db, $in['ref'] ?? '');
    $e = $db->querySingle('SELECT title, body FROM entries WHERE id = ' . $id, true);
    api_json(['ok' => true] + entry_hints($db, $id, (string)$e['title'], (string)$e['body']));
  }

  default:
    api_json(['ok' => false, 'error' => 'comando sconosciuto: ' . $cmd], 400);
  }
} catch (Throwable $e) {
  api_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
