<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/lib_nlp.php';
require __DIR__ . '/nav.php';
require_login();

$site = (string)(cfg()['site_name'] ?? 'snippet');
$db = db_ro();

/* ---------- totali ---------- */
$tot = [
  'entries'   => (int)$db->querySingle('SELECT COUNT(*) FROM entries WHERE archived=0'),
  'archived'  => (int)$db->querySingle('SELECT COUNT(*) FROM entries WHERE archived=1'),
  'words'     => (int)$db->querySingle('SELECT COALESCE(SUM(word_count),0) FROM entries WHERE archived=0'),
  'chars'     => (int)$db->querySingle('SELECT COALESCE(SUM(char_count),0) FROM entries WHERE archived=0'),
  'tags_m'    => (int)$db->querySingle("SELECT COUNT(*) FROM tags WHERE kind='manual'"),
  'tags_a'    => (int)$db->querySingle("SELECT COUNT(*) FROM tags WHERE kind='auto'"),
  'links'     => (int)$db->querySingle('SELECT COUNT(*) FROM links'),
  'notes'     => (int)$db->querySingle('SELECT COUNT(*) FROM notes'),
  'attach'    => (int)$db->querySingle('SELECT COUNT(*) FROM attachments'),
];
$avg_words = $tot['entries'] > 0 ? round($tot['words'] / $tot['entries'], 1) : 0;

$sources = [];
$r = $db->query("SELECT source, COUNT(*) c FROM entries WHERE archived=0 GROUP BY source ORDER BY c DESC");
while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $sources[(string)$x['source']] = (int)$x['c'];

/* ---------- serie temporale (aggregata in PHP nel fuso locale) ---------- */
$days = [];      // 'Y-m-d' locale => n
$months = [];    // 'Y-m' locale => n
$heat = array_fill(1, 7, array_fill(0, 24, 0)); // [weekday 1..7][hour 0..23]
$r = $db->query('SELECT created_at FROM entries WHERE archived=0');
while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
  try {
    $dt = new DateTime((string)$x['created_at'], new DateTimeZone('UTC'));
    $dt->setTimezone(tzobj());
  } catch (Throwable $e) { continue; }
  $d = $dt->format('Y-m-d');
  $days[$d] = ($days[$d] ?? 0) + 1;
  $m = $dt->format('Y-m');
  $months[$m] = ($months[$m] ?? 0) + 1;
  $heat[(int)$dt->format('N')][(int)$dt->format('G')]++;
}
ksort($days);
ksort($months);

/* streak sui giorni locali con almeno una voce */
$dset = array_keys($days);
$active_days = count($dset);
$first_day = $dset[0] ?? null;
$today = (new DateTime('now', tzobj()))->format('Y-m-d');
$yest  = (new DateTime('now', tzobj()))->modify('-1 day')->format('Y-m-d');

$longest = 0; $run = 0; $prev = null;
foreach ($dset as $d) {
  if ($prev !== null && (new DateTime($prev))->modify('+1 day')->format('Y-m-d') === $d) {
    $run++;
  } else {
    $run = 1;
  }
  if ($run > $longest) $longest = $run;
  $prev = $d;
}
$current = 0;
if ($dset) {
  $last = end($dset);
  if ($last === $today || $last === $yest) {
    $current = 1;
    $cursor = $last;
    while (true) {
      $p = (new DateTime($cursor))->modify('-1 day')->format('Y-m-d');
      if (isset($days[$p])) { $current++; $cursor = $p; } else break;
    }
  }
}

/* ---------- ultimi 12 mesi ---------- */
$m12 = [];
$cur = new DateTime('first day of this month', tzobj());
for ($i = 11; $i >= 0; $i--) {
  $k = (clone $cur)->modify("-$i month")->format('Y-m');
  $m12[$k] = $months[$k] ?? 0;
}
$m12max = max(1, ...array_values($m12));

/* ---------- top tag manuali ---------- */
$toptags = [];
$r = $db->query("
  SELECT t.name, COUNT(DISTINCT et.entry_id) c
  FROM tags t JOIN entry_tags et ON et.tag_id = t.id
  WHERE t.kind='manual'
  GROUP BY t.id ORDER BY c DESC, t.name LIMIT 15
");
while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $toptags[] = $x;
$ttmax = $toptags ? max(1, ...array_map(fn($t) => (int)$t['c'], $toptags)) : 1;

/* ---------- hub (grado piu' alto) ---------- */
$hubs = [];
$r = $db->query("
  SELECT e.id, e.slug, e.title, e.body, COUNT(*) deg
  FROM (SELECT src_id id FROM links UNION ALL SELECT dst_id FROM links) l
  JOIN entries e ON e.id = l.id
  WHERE e.archived=0
  GROUP BY e.id ORDER BY deg DESC, e.created_at DESC LIMIT 10
");
while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $hubs[] = $x;

/* ---------- orfane (nessun arco) ---------- */
$orphan_ids = [];
$r = $db->query("
  SELECT e.id, e.slug, e.title, e.body, e.created_at
  FROM entries e
  WHERE e.archived=0
    AND e.id NOT IN (SELECT src_id FROM links UNION SELECT dst_id FROM links)
  ORDER BY e.created_at DESC
");
$orphans = [];
while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $orphans[] = $x;

/* ---------- vocabolario ---------- */
$vocab = [];
$r = $db->query('SELECT term, SUM(freq) f, COUNT(*) docs FROM entry_keywords GROUP BY term ORDER BY f DESC, docs DESC LIMIT 40');
while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $vocab[] = $x;
$vmax = $vocab ? max(1, ...array_map(fn($v) => (int)$v['f'], $vocab)) : 1;

$heatmax = 0;
foreach ($heat as $row) foreach ($row as $v) if ($v > $heatmax) $heatmax = $v;
$wdlabels = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Gio', 5 => 'Ven', 6 => 'Sab', 7 => 'Dom'];

function bar_row(string $label, int $val, int $max, string $extra = ''): string {
  $pct = $max > 0 ? max(2, (int)round($val / $max * 100)) : 0;
  return '<div class="row" style="gap:8px;margin:3px 0">'
    . '<span class="meta" style="flex:0 0 130px;text-align:right">' . h($label) . '</span>'
    . '<span style="flex:1;background:var(--bg);border:1px solid var(--border);border-radius:3px;overflow:hidden">'
    . '<span style="display:block;height:14px;width:' . $pct . '%;background:var(--accent)"></span></span>'
    . '<span class="meta" style="flex:0 0 60px">' . $val . ' ' . $extra . '</span></div>';
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
<title><?=h($site)?> — Statistiche</title>

<?php render_header('Statistiche', 'stats'); ?>

<div class="wrap">
  <div class="card">
    <b>Numeri</b>
    <hr>
    <div class="row" style="gap:24px">
      <div><div class="meta">voci</div><div style="font-size:1.4rem"><?=$tot['entries']?></div></div>
      <div><div class="meta">parole</div><div style="font-size:1.4rem"><?=number_format($tot['words'], 0, ',', '.')?></div></div>
      <div><div class="meta">media parole/voce</div><div style="font-size:1.4rem"><?=$avg_words?></div></div>
      <div><div class="meta">giorni attivi</div><div style="font-size:1.4rem"><?=$active_days?></div></div>
      <div><div class="meta">streak attuale</div><div style="font-size:1.4rem"><?=$current?> gg</div></div>
      <div><div class="meta">streak record</div><div style="font-size:1.4rem"><?=$longest?> gg</div></div>
    </div>
    <div class="meta" style="margin-top:10px">
      archiviate <?=$tot['archived']?> · tag <?=$tot['tags_m']?> manuali / <?=$tot['tags_a']?> auto ·
      archi <?=$tot['links']?> · note <?=$tot['notes']?> · allegati <?=$tot['attach']?>
      <?php if ($first_day): ?> · prima voce <?=h(fmt_day($first_day))?><?php endif; ?>
      <?php if ($sources): ?> · fonti:
        <?php foreach ($sources as $s => $c): ?><span class="badge"><?=h($s)?> <?=$c?></span> <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <b>Attività — ultimi 12 mesi</b>
    <hr>
    <?php foreach ($m12 as $k => $v): ?>
      <?= bar_row($k, $v, $m12max, 'voci') ?>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <b>Quando scrivi</b>
    <div class="meta" style="margin-top:4px">ora locale × giorno della settimana · cella più scura = più voci (max <?=$heatmax?>)</div>
    <div style="overflow-x:auto; margin-top:8px">
      <table style="border-collapse:collapse; font-size:.62rem">
        <tr>
          <td></td>
          <?php for ($hh = 0; $hh < 24; $hh++): ?>
            <td class="meta" style="text-align:center; width:20px"><?= $hh % 2 === 0 ? $hh : '' ?></td>
          <?php endfor; ?>
        </tr>
        <?php for ($wd = 1; $wd <= 7; $wd++): ?>
          <tr>
            <td class="meta" style="padding-right:6px; text-align:right"><?=$wdlabels[$wd]?></td>
            <?php for ($hh = 0; $hh < 24; $hh++): $v = $heat[$wd][$hh];
              $op = $heatmax > 0 && $v > 0 ? max(0.12, $v / $heatmax) : 0; ?>
              <td title="<?=$wdlabels[$wd]?> <?=$hh?>:00 — <?=$v?>"
                  style="width:20px;height:16px;border:1px solid var(--border);<?= $v > 0 ? 'background:var(--accent);opacity:' . round($op, 2) : 'background:var(--bg)' ?>"></td>
            <?php endfor; ?>
          </tr>
        <?php endfor; ?>
      </table>
    </div>
  </div>

  <div class="card grid" style="margin-bottom:0">
    <div>
      <b>Tag più usati</b>
      <hr>
      <?php foreach ($toptags as $t): ?>
        <?= bar_row((string)$t['name'], (int)$t['c'], $ttmax, 'voci') ?>
      <?php endforeach; ?>
      <?php if (!$toptags): ?><div class="meta">Nessun tag manuale.</div><?php endif; ?>
    </div>
    <div>
      <b>Voci più connesse (hub)</b>
      <hr>
      <ul class="small">
        <?php foreach ($hubs as $x): ?>
          <li><a href="<?=h(entry_url($x))?>"><?=h((string)($x['title'] ?: first_line((string)$x['body'], 60)))?></a>
            <span class="badge"><?= (int)$x['deg'] ?> archi</span></li>
        <?php endforeach; ?>
        <?php if (!$hubs): ?><li class="meta">Nessun collegamento ancora.</li><?php endif; ?>
      </ul>
    </div>
  </div>

  <div class="card">
    <b>Voci isolate</b>
    <span class="meta">(<?=count($orphans)?>) — nessuna correlazione né backlink. Buoni candidati da ricollegare.</span>
    <?php if ($orphans): ?>
      <ul class="small" style="margin-top:8px">
        <?php foreach (array_slice($orphans, 0, 15) as $x): ?>
          <li><a href="<?=h(entry_url($x))?>"><?=h((string)($x['title'] ?: first_line((string)$x['body'], 70)))?></a>
            <span class="meta"><?=h(fmt_dt((string)$x['created_at'], false))?></span></li>
        <?php endforeach; ?>
        <?php if (count($orphans) > 15): ?><li class="meta">… e altre <?=count($orphans) - 15?></li><?php endif; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card">
    <b>Vocabolario ricorrente</b>
    <span class="meta">termini più frequenti nel corpo di tutte le voci (stopword escluse)</span>
    <div class="row" style="margin-top:10px">
      <?php foreach ($vocab as $v): $sz = 0.75 + ((int)$v['f'] / $vmax) * 0.9; ?>
        <a href="search.php?q=<?=urlencode((string)$v['term'])?>" class="badge"
           style="font-size:<?=round($sz, 2)?>rem" title="<?= (int)$v['f'] ?> occorrenze in <?= (int)$v['docs'] ?> voci">
          <?=h((string)$v['term'])?>
        </a>
      <?php endforeach; ?>
      <?php if (!$vocab): ?><span class="meta">Ancora niente.</span><?php endif; ?>
    </div>
  </div>
</div>
