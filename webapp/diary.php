<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/lib_nlp.php';
require __DIR__ . '/nav.php';
require_login();

$site = (string)(cfg()['site_name'] ?? 'snippet');
$db = db_ro();

$mode = (string)($_GET['range'] ?? 'month');
if (!in_array($mode, ['day', 'week', 'month', 'all'], true)) $mode = 'month';
$day = (string)($_GET['day'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
  $day = (new DateTime('now', tzobj()))->format('Y-m-d');
}
$tag = nlp_tag_normalize((string)($_GET['tag'] ?? ''));
$show_archived = (int)($_GET['archived'] ?? 0) === 1;
$page = (isset($_GET['page']) && ctype_digit((string)$_GET['page'])) ? max(1, (int)$_GET['page']) : 1;
$per = 40;

/* intervallo locale -> UTC */
$where = ['1=1'];
$params = [];
if (!$show_archived) $where[] = 'e.archived = 0';

if ($mode !== 'all') {
  $range = match ($mode) {
    'week'  => [
      (new DateTime($day, tzobj()))->modify('monday this week')->format('Y-m-d'),
      (new DateTime($day, tzobj()))->modify('sunday this week')->format('Y-m-d'),
    ],
    'month' => [
      (new DateTime($day, tzobj()))->format('Y-m-01'),
      (new DateTime($day, tzobj()))->format('Y-m-t'),
    ],
    default => [$day, $day],
  };
  $start_utc = (new DateTime($range[0] . ' 00:00:00', tzobj()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  $end_utc   = (new DateTime($range[1] . ' 23:59:59', tzobj()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  $where[] = 'e.created_at BETWEEN :start AND :end';
  $params[':start'] = [$start_utc, SQLITE3_TEXT];
  $params[':end']   = [$end_utc, SQLITE3_TEXT];
}

$join = '';
if ($tag !== '') {
  $join = 'JOIN entry_tags et ON et.entry_id = e.id JOIN tags t ON t.id = et.tag_id';
  $where[] = 't.name = :tag';
  $params[':tag'] = [$tag, SQLITE3_TEXT];
}

$wsql = implode(' AND ', $where);

$cst = $db->prepare("SELECT COUNT(DISTINCT e.id) AS c FROM entries e $join WHERE $wsql");
foreach ($params as $k => [$v, $t]) $cst->bindValue($k, $v, $t);
$total = (int)$cst->execute()->fetchArray(SQLITE3_ASSOC)['c'];
$pages = (int)ceil($total / $per);
if ($pages > 0 && $page > $pages) $page = $pages;
$offset = ($page - 1) * $per;

$sql = "
  SELECT DISTINCT e.id, e.slug, e.title, e.body, e.created_at, e.source,
         e.pinned, e.archived, e.word_count
  FROM entries e $join
  WHERE $wsql
  ORDER BY e.created_at DESC
  LIMIT :lim OFFSET :off
";
$st = $db->prepare($sql);
foreach ($params as $k => [$v, $t]) $st->bindValue($k, $v, $t);
$st->bindValue(':lim', $per, SQLITE3_INTEGER);
$st->bindValue(':off', $offset, SQLITE3_INTEGER);
$rows = [];
$r = $st->execute();
while ($x = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = $x;

/* tag per le voci mostrate */
$tags_by_entry = [];
if ($rows) {
  $ids = array_map(fn($x) => (int)$x['id'], $rows);
  $in = implode(',', $ids);
  $tr = $db->query(
    "SELECT et.entry_id, t.name, et.auto FROM entry_tags et JOIN tags t ON t.id = et.tag_id
     WHERE et.entry_id IN ($in) ORDER BY et.auto ASC, t.name ASC"
  );
  while ($tr && ($x = $tr->fetchArray(SQLITE3_ASSOC))) {
    $tags_by_entry[(int)$x['entry_id']][] = $x;
  }
}

function diary_qs(array $over): string {
  $base = [
    'range'    => $_GET['range']    ?? 'month',
    'day'      => $_GET['day']      ?? '',
    'tag'      => $_GET['tag']      ?? '',
    'archived' => $_GET['archived'] ?? '',
  ];
  return http_build_query(array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null));
}

/* raggruppa per giorno locale */
$groups = [];
foreach ($rows as $x) {
  $d = local_ymd((string)$x['created_at']);
  $groups[$d][] = $x;
}
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<meta name="theme-color" content="#2f6feb">
<link rel="manifest" href="manifest.php">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script src="assets/pwa.js" defer></script>
<title><?=h($site)?> — Diario</title>

<?php render_header('Diario', 'diary'); ?>

<div class="wrap">
  <form method="get" class="card">
    <div class="row">
      <select name="range">
        <?php foreach (['day' => 'Giorno', 'week' => 'Settimana', 'month' => 'Mese', 'all' => 'Tutto'] as $k => $lab): ?>
          <option value="<?=$k?>" <?= $mode === $k ? 'selected' : '' ?>><?=$lab?></option>
        <?php endforeach; ?>
      </select>
      <input type="date" name="day" value="<?=h($day)?>">
      <input class="grow" name="tag" value="<?=h($tag)?>" placeholder="Filtra per tag">
      <label class="meta" style="display:flex;align-items:center;gap:6px">
        <input type="checkbox" name="archived" value="1" <?= $show_archived ? 'checked' : '' ?> style="width:auto"> archiviate
      </label>
      <button class="btn" type="submit">Filtra</button>
    </div>
  </form>

  <div class="card">
    <b><?=$total?> voci</b>
    <?php if ($pages > 1): ?><span class="meta"> · pagina <?=$page?>/<?=$pages?></span><?php endif; ?>
    <hr>
    <?php if (!$rows): ?>
      <div class="meta">Nessuna voce nell'intervallo scelto.</div>
    <?php endif; ?>

    <?php foreach ($groups as $d => $items): ?>
      <div class="meta" style="margin-top:14px"><b><?=h(fmt_day($d))?></b></div>
      <?php foreach ($items as $x): ?>
        <div class="feed-entry">
          <div class="row" style="gap:6px">
            <a href="<?=h(entry_url($x))?>"><b><?=h((string)($x['title'] ?: first_line((string)$x['body'], 80)))?></b></a>
            <?php if ((int)$x['pinned'] === 1): ?><span class="badge">📌</span><?php endif; ?>
            <?php if ((int)$x['archived'] === 1): ?><span class="badge">🗄</span><?php endif; ?>
            <span class="badge"><?=h((string)$x['source'])?></span>
          </div>
          <div class="meta" style="margin-top:4px">
            <?=h(fmt_dt((string)$x['created_at']))?> · <?= (int)$x['word_count'] ?> parole · <?=h((string)$x['slug'])?>
          </div>
          <?php if (!empty($tags_by_entry[(int)$x['id']])): ?>
            <div class="row" style="margin-top:6px">
              <?php foreach ($tags_by_entry[(int)$x['id']] as $tg): ?>
                <a class="badge <?= (int)$tg['auto'] === 0 ? 'red-stamp' : '' ?>" href="?<?=h(diary_qs(['tag' => (string)$tg['name'], 'page' => '']))?>"><?=h((string)$tg['name'])?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
      <div class="btns" style="justify-content:center; margin-top:16px">
        <?php if ($page > 1): ?>
          <a class="btn" href="?<?=h(diary_qs(['page' => (string)($page - 1)]))?>">◀ Prec</a>
        <?php endif; ?>
        <span class="meta" style="align-self:center">&nbsp;<?=$page?> / <?=$pages?>&nbsp;</span>
        <?php if ($page < $pages): ?>
          <a class="btn" href="?<?=h(diary_qs(['page' => (string)($page + 1)]))?>">Succ ▶</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
