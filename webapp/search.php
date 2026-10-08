<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/lib_nlp.php';
require __DIR__ . '/lib_search.php';
require __DIR__ . '/nav.php';
require_login();

$site = (string)(cfg()['site_name'] ?? 'snippet');
$me = current_user();

/* ===== POST: salva / elimina ricerca ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check()) { http_response_code(403); die('CSRF non valido'); }
  $action = (string)($_POST['action'] ?? '');
  $ret = (string)($_POST['ret'] ?? '');
  try {
    $dbw = db_rw();
    if ($action === 'save') {
      $name = trim((string)($_POST['name'] ?? ''));
      $q    = trim((string)($_POST['q'] ?? ''));
      if ($name === '' || $q === '') {
        flash_set('err', 'Nome e query sono obbligatori.');
      } elseif (mb_strlen($name, 'UTF-8') > 80) {
        flash_set('err', 'Nome troppo lungo (max 80).');
      } else {
        $st = $dbw->prepare(
          "INSERT INTO saved_searches(owner, name, q) VALUES(:o,:n,:q)
           ON CONFLICT(owner, name) DO UPDATE SET q = excluded.q, created_at = datetime('now')"
        );
        $st->bindValue(':o', $me, SQLITE3_TEXT);
        $st->bindValue(':n', $name, SQLITE3_TEXT);
        $st->bindValue(':q', $q, SQLITE3_TEXT);
        $st->execute();
        flash_set('ok', 'Ricerca «' . $name . '» salvata.');
      }
    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      $st = $dbw->prepare('DELETE FROM saved_searches WHERE id=:i AND owner=:o');
      $st->bindValue(':i', $id, SQLITE3_INTEGER);
      $st->bindValue(':o', $me, SQLITE3_TEXT);
      $st->execute();
      flash_set('ok', 'Ricerca eliminata.');
    }
  } catch (Throwable $e) {
    flash_set('err', $e->getMessage());
  }
  header('Location: ' . ($ret !== '' && str_starts_with($ret, 'search.php') ? $ret : 'search.php'));
  exit;
}

$flash = flash_take();
$db = db_ro();

$q       = trim((string)($_GET['q'] ?? ''));
// compatibilita' con i vecchi link (?tag=..&source=..): diventano filtri nel testo
$tag     = nlp_tag_normalize((string)($_GET['tag'] ?? ''));
$source  = (string)($_GET['source'] ?? '');
if ($tag !== '') $q = trim($q . ' tag:' . str_replace(' ', '_', $tag));
if (in_array($source, ['web', 'telegram', 'import'], true)) $q = trim($q . ' fonte:' . $source);
$mode    = (string)($_GET['mode'] ?? 'auto');
if (!in_array($mode, ['auto', 'misto', 'parole', 'significato'], true)) $mode = 'auto';
$sort    = (string)($_GET['sort'] ?? 'rel');
if (!in_array($sort, ['date', 'rel'], true)) $sort = 'rel';
$show_archived = (int)($_GET['archived'] ?? 0) === 1;
$limit = (int)($_GET['limit'] ?? 25);
if (!in_array($limit, [10, 25, 50, 100], true)) $limit = 25;
$page = (isset($_GET['page']) && ctype_digit((string)$_GET['page'])) ? max(1, (int)$_GET['page']) : 1;

$cur_qs = http_build_query(array_filter([
  'q' => $q, 'mode' => $mode !== 'auto' ? $mode : '', 'sort' => $sort,
  'archived' => $show_archived ? 1 : '', 'limit' => $limit,
], static fn($v) => $v !== '' && $v !== null));

/* ricerche salvate */
$saved = [];
$st = $db->prepare('SELECT id, name, q FROM saved_searches WHERE owner=:o ORDER BY name COLLATE NOCASE');
$st->bindValue(':o', $me, SQLITE3_TEXT);
$rs = $st->execute();
while ($r = $rs->fetchArray(SQLITE3_ASSOC)) $saved[] = $r;

$rows = [];
$total = 0;
$pages = 0;
$err = '';
$res = null;
if ($q !== '') {
  try {
    $res = search_run($db, $q, $mode, $page, $limit, $show_archived, $sort);
    $total = $res['total'];
    $pages = (int)ceil($total / $limit);
    $rows = $res['items'];
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}
$ml_on = ml_enabled();
$MODE_LABEL = ['misto' => 'parole + significato', 'parole' => 'parole', 'significato' => 'significato', 'filtri' => 'solo filtri'];

function search_page_url(int $n): string {
  $p = $_GET;
  $p['page'] = $n;
  return 'search.php?' . http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== null));
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
<title><?=h($site)?> — Cerca</title>

<?php render_header('Cerca', 'search'); ?>

<div class="wrap">
  <?php if ($flash): ?>
    <div class="card"><b><?= $flash[0] === 'ok' ? 'OK:' : 'Errore:' ?></b> <?=h((string)$flash[1])?></div>
  <?php endif; ?>

  <?php if ($saved): ?>
    <div class="card">
      <b>Ricerche salvate</b>
      <hr>
      <?php foreach ($saved as $s): ?>
        <div class="row" style="justify-content:space-between; margin:6px 0">
          <div class="grow">
            <a href="search.php?q=<?=urlencode((string)$s['q'])?>"><b><?=h((string)$s['name'])?></b></a>
            <span class="meta">— <code><?=h((string)$s['q'])?></code></span>
          </div>
          <form method="post" onsubmit="return confirm('Eliminare «<?=h((string)$s['name'])?>»?')">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="ret" value="search.php?<?=h($cur_qs)?>">
            <button class="btn" type="submit">Elimina</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="get" class="card">
    <div class="row">
      <input class="grow" name="q" value="<?=h($q)?>" autofocus
             placeholder='cosa cerchi… (anche: tag:lavoro persona:jamal da:01/09/2026)'>
      <select name="mode" title="come cercare">
        <?php foreach (['auto' => $ml_on ? 'parole + significato' : 'parole', 'parole' => 'solo parole', 'significato' => 'per significato'] as $k => $lab):
          if ($k === 'significato' && !$ml_on) continue; ?>
          <option value="<?=$k?>" <?= $mode === $k ? 'selected' : '' ?>><?=h($lab)?></option>
        <?php endforeach; ?>
      </select>
      <select name="sort">
        <option value="rel" <?= $sort === 'rel' ? 'selected' : '' ?>>per rilevanza</option>
        <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>per data</option>
      </select>
      <select name="limit">
        <?php foreach ([10, 25, 50, 100] as $n): ?>
          <option value="<?=$n?>" <?= $limit === $n ? 'selected' : '' ?>><?=$n?>/pag</option>
        <?php endforeach; ?>
      </select>
      <label class="meta" style="display:flex;align-items:center;gap:6px">
        <input type="checkbox" name="archived" value="1" <?= $show_archived ? 'checked' : '' ?> style="width:auto"> archiviate
      </label>
      <button class="btn" type="submit">Cerca</button>
    </div>
    <div class="meta" style="margin-top:6px">
      Filtri: <span class="badge">tag:lavoro</span> <span class="badge">persona:jamal</span>
      <span class="badge">persona:"Mario Scarpati"</span> <span class="badge">da:01/09/2026</span>
      <span class="badge">a:09/2026</span> <span class="badge">tema:2</span> <span class="badge">fonte:telegram</span>
      · Parole: <span class="badge">diario NOT cucina</span> <span class="badge">"una frase"</span> <span class="badge">berl*</span>
      <?php if ($ml_on): ?>· Per significato: descrivi a parole tue, es. <span class="badge">litigi in ufficio</span><?php endif; ?>
    </div>
  </form>

  <?php if ($err): ?>
    <div class="card"><b>Errore:</b> <?=h($err)?></div>
  <?php endif; ?>

  <?php if ($q !== '' && !$err): ?>
    <div class="card">
      <div class="row" style="justify-content:space-between">
        <div>
          <b><?=$total?> risultati</b>
          <span class="meta"> · <?=h($MODE_LABEL[$res['mode']] ?? $res['mode'])?></span>
          <?php if ($res['parsed']['desc'] !== ''): ?><span class="meta"> · <?=h($res['parsed']['desc'])?></span><?php endif; ?>
          <?php if ($pages > 1): ?><span class="meta"> · pagina <?=$page?>/<?=$pages?></span><?php endif; ?>
          <?php if ($res['note'] !== ''): ?><div class="meta">⚠ <?=h($res['note'])?></div><?php endif; ?>
        </div>
        <form method="post" class="row" style="gap:6px">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="q" value="<?=h($q)?>">
          <input type="hidden" name="ret" value="search.php?<?=h($cur_qs)?>">
          <input name="name" maxlength="80" placeholder="nome ricerca…" required>
          <button class="btn" type="submit">Salva ricerca</button>
        </form>
      </div>
      <hr>
      <?php if (!$rows): ?>
        <div class="meta">Nessun risultato.</div>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <div class="result-entry">
          <div class="row" style="gap:6px">
            <a href="<?=h(entry_url($r))?>"><b><?=h((string)($r['title'] ?: first_line((string)$r['body'], 80)))?></b></a>
            <span class="badge"><?=h((string)$r['source'])?></span>
            <span class="meta"><?=h(fmt_dt((string)$r['created_at']))?> · <?=h((string)$r['slug'])?></span>
            <?php if ($r['sim'] !== null): ?><span class="badge" title="affinità di significato">🧭 <?= (int)round($r['sim'] * 100) ?>%</span><?php endif; ?>
          </div>
          <?php if (!empty($r['snip'])): ?>
            <div class="small result-snippet"><?= str_replace(["\x02", "\x03"], ['<mark>', '</mark>'], h((string)$r['snip'])) ?></div>
          <?php else: ?>
            <div class="small result-snippet"><?=h(mb_substr(trim(preg_replace('/\s+/', ' ', (string)$r['body']) ?? ''), 0, 200, 'UTF-8'))?>…</div>
          <?php endif; ?>
        </div>
        <hr>
      <?php endforeach; ?>

      <?php if ($pages > 1): ?>
        <div class="btns" style="justify-content:center; margin-top:14px">
          <?php if ($page > 1): ?><a class="btn" href="<?=h(search_page_url($page - 1))?>">◀ Prec</a><?php endif; ?>
          <span class="meta" style="align-self:center">&nbsp;<?=$page?> / <?=$pages?>&nbsp;</span>
          <?php if ($page < $pages): ?><a class="btn" href="<?=h(search_page_url($page + 1))?>">Succ ▶</a><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
