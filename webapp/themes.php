<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/lib_nlp.php';
require __DIR__ . '/nav.php';
require_login();

/**
 * Temi: gruppi di voci fortemente collegate fra loro (graph_clusters() in
 * lib_nlp.php), ricalcolati a ogni salvataggio e ogni notte. L'etichetta e'
 * fatta dai termini che caratterizzano il gruppo.
 */

$site = (string)(cfg()['site_name'] ?? 'snippet');
$db = db_ro();
$tid = (int)($_GET['id'] ?? 0);

$themes = [];
$r = $db->query("SELECT c.id, c.label, c.size, c.terms, MIN(e.created_at) first, MAX(e.created_at) last,
                   SUM(e.created_at >= datetime('now', '-14 days')) recent
                 FROM clusters c JOIN entries e ON e.cluster = c.id
                 GROUP BY c.id ORDER BY recent DESC, c.size DESC, last DESC");
while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $themes[(int)$x['id']] = $x;

function theme_detail(SQLite3 $db, int $tid): array {
  $out = ['entries' => [], 'persons' => [], 'tags' => []];
  $r = $db->query("SELECT id, slug, title, body, created_at FROM entries WHERE cluster = $tid ORDER BY created_at DESC");
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $out['entries'][] = $x;
  $r = $db->query("SELECT p.id, p.name, COUNT(*) c FROM entry_persons ep JOIN persons p ON p.id = ep.person_id
                   JOIN entries e ON e.id = ep.entry_id WHERE e.cluster = $tid GROUP BY p.id ORDER BY c DESC");
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $out['persons'][] = $x;
  $r = $db->query("SELECT t.name, COUNT(*) c FROM entry_tags et JOIN tags t ON t.id = et.tag_id
                   JOIN entries e ON e.id = et.entry_id WHERE e.cluster = $tid
                   GROUP BY t.id HAVING c >= 2 ORDER BY c DESC, t.name LIMIT 15");
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $out['tags'][] = $x;
  return $out;
}

$title = $tid > 0 && isset($themes[$tid]) ? 'Tema: ' . $themes[$tid]['label'] : 'Temi';
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<meta name="theme-color" content="#2f6feb">
<link rel="manifest" href="manifest.php">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script src="assets/pwa.js" defer></script>
<title><?=h($site)?> — <?=h($title)?></title>
<?php render_header($title, 'themes'); ?>

<div class="wrap">
<?php if ($tid > 0): ?>
  <?php if (!isset($themes[$tid])): ?>
    <div class="card">Tema non trovato (i temi si ricalcolano: forse è cambiato). <a href="themes.php">Tutti i temi</a></div>
  <?php else: $t = $themes[$tid]; $d = theme_detail($db, $tid); ?>
    <div class="card">
      <div class="row" style="justify-content:space-between">
        <b><?=h((string)$t['label'])?></b>
        <a class="btn" href="themes.php">← tutti i temi</a>
      </div>
      <div class="meta" style="margin-top:6px"><?= (int)$t['size'] ?> voci · dal <?=h(fmt_dt((string)$t['first'], false))?> al <?=h(fmt_dt((string)$t['last'], false))?>
        <?php if (trim((string)$t['terms']) !== ''): ?> · termini: <?=h(str_replace(',', ', ', (string)$t['terms']))?><?php endif; ?></div>
      <?php if ($d['persons'] || $d['tags']): ?>
        <div class="row" style="margin-top:10px">
          <?php foreach ($d['persons'] as $p): ?><a class="badge red-stamp" href="people.php?id=<?= (int)$p['id'] ?>">👤 <?=h((string)$p['name'])?></a><?php endforeach; ?>
          <?php foreach ($d['tags'] as $g): ?><a class="badge" href="tags.php?tag=<?=urlencode((string)$g['name'])?>"><?=h((string)$g['name'])?> · <?= (int)$g['c'] ?></a><?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="card">
      <?php foreach ($d['entries'] as $x): ?>
        <div class="feed-entry">
          <a href="<?=h(entry_url($x))?>"><b><?=h((string)($x['title'] ?: first_line((string)$x['body'], 80)))?></b></a>
          <div class="meta"><?=h(fmt_dt((string)$x['created_at']))?></div>
          <div class="small" style="margin-top:4px"><?=h(mb_substr(trim(preg_replace('/\s+/', ' ', (string)$x['body']) ?? ''), 0, 220, 'UTF-8'))?>…</div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php else: ?>
  <div class="card">
    <div class="meta">Gruppi di voci che parlano della stessa cosa, riconosciuti dalle correlazioni (parole, significato, tag, persone).
      In alto quelli con voci delle ultime due settimane. Si aggiornano da soli.</div>
  </div>
  <?php if (!$themes): ?>
    <div class="card meta">Ancora nessun tema: servono almeno due voci ben collegate fra loro.</div>
  <?php endif; ?>
  <?php foreach ($themes as $id => $t): $d = theme_detail($db, $id); ?>
    <div class="card">
      <div class="row" style="justify-content:space-between">
        <a href="themes.php?id=<?=$id?>"><b><?=h((string)$t['label'])?></b></a>
        <span class="meta"><?= (int)$t['size'] ?> voci<?php if ((int)$t['recent'] > 0): ?> · <b><?= (int)$t['recent'] ?> recenti</b><?php endif; ?>
          · <?=h(fmt_dt((string)$t['first'], false))?> → <?=h(fmt_dt((string)$t['last'], false))?></span>
      </div>
      <ul class="small" style="margin-top:6px">
        <?php foreach (array_slice($d['entries'], 0, 5) as $x): ?>
          <li><a href="<?=h(entry_url($x))?>"><?=h((string)($x['title'] ?: first_line((string)$x['body'], 70)))?></a>
            <span class="meta"><?=h(fmt_dt((string)$x['created_at'], false))?></span></li>
        <?php endforeach; ?>
        <?php if (count($d['entries']) > 5): ?><li class="meta"><a href="themes.php?id=<?=$id?>">… altre <?=count($d['entries']) - 5?></a></li><?php endif; ?>
      </ul>
      <?php if ($d['persons']): ?>
        <div class="row" style="margin-top:6px">
          <?php foreach ($d['persons'] as $p): ?><a class="badge red-stamp" href="people.php?id=<?= (int)$p['id'] ?>">👤 <?=h((string)$p['name'])?></a><?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>
