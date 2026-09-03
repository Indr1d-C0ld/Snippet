<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/lib_nlp.php';
require __DIR__ . '/nav.php';
require_login();

$site = (string)(cfg()['site_name'] ?? 'snippet');
$err = '';
$body_val = '';
$title_val = '';
$tags_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check()) { http_response_code(403); die('CSRF non valido'); }

  $ajax = ($_POST['ajax'] ?? '') === '1';
  $body_val  = (string)($_POST['body'] ?? '');
  $title_val = trim((string)($_POST['title'] ?? ''));
  $tags_val  = trim((string)($_POST['tags'] ?? ''));

  try {
    if (trim($body_val) === '') {
      throw new RuntimeException('Scrivi qualcosa prima di salvare.');
    }
    $tags = [];
    foreach (preg_split('/,/', $tags_val) as $t) {
      $n = nlp_tag_normalize($t);
      if ($n !== '') $tags[] = $n;
    }

    $db = db_rw();
    $res = entry_save($db, [
      'raw'    => $body_val,
      'title'  => $title_val,
      'tags'   => $tags,
      'author' => current_user(),
      'source' => 'web',
    ]);
    if ($ajax) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => true, 'url' => entry_url($res['slug']), 'slug' => $res['slug']], JSON_UNESCAPED_UNICODE);
      exit;
    }
    flash_set('ok', 'Voce salvata: ' . $res['slug']);
    header('Location: ' . entry_url($res['slug']));
    exit;
  } catch (Throwable $e) {
    $err = $e->getMessage();
    if ($ajax) {
      http_response_code(422);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
}

$flash = flash_take();

// Ultime voci, per contesto.
$recent = [];
try {
  $rs = db_ro()->query(
    'SELECT id, slug, title, body, created_at, source
     FROM entries WHERE archived = 0
     ORDER BY created_at DESC LIMIT 8'
  );
  while ($rs && ($r = $rs->fetchArray(SQLITE3_ASSOC))) $recent[] = $r;
} catch (Throwable $e) { /* db vuoto */ }
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<meta name="theme-color" content="#2f6feb">
<link rel="manifest" href="manifest.php">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script src="assets/pwa.js" defer></script>
<meta name="csrf" content="<?=h(csrf_token())?>">
<title><?=h($site)?> — Scrivi</title>

<?php render_header('Scrivi', 'compose'); ?>

<div class="wrap">
  <?php if ($flash): ?>
    <div class="card"><b><?= $flash[0] === 'ok' ? 'OK:' : 'Errore:' ?></b> <?=h((string)$flash[1])?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="card"><b>Errore:</b> <?=h($err)?></div>
  <?php endif; ?>

  <form method="post" class="card compose" id="composeForm">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">

    <textarea name="body" class="compose-body" autofocus
      placeholder="Un pensiero, una nota al volo, una riflessione…&#10;&#10;Prima riga breve + riga vuota = titolo. #tag inline. [[123]] per collegare un'altra voce.&#10;Direttive: !data:2026-09-01  !nolink  !pin  !tag:studio, viaggio"><?=h($body_val)?></textarea>

    <div class="row" style="margin-top:10px">
      <input class="grow" name="title" value="<?=h($title_val)?>" placeholder="Titolo (facoltativo — se vuoto lo deduco)">
      <input class="grow" name="tags" value="<?=h($tags_val)?>" placeholder="Tag manuali, separati da virgola">
    </div>

    <div class="row" style="margin-top:10px; justify-content:space-between">
      <span class="meta">Le keyword e le correlazioni vengono calcolate al salvataggio.</span>
      <button class="btn" type="submit">Salva</button>
    </div>
  </form>

  <div class="card">
    <b>Ultime voci</b>
    <?php if (!$recent): ?>
      <div class="meta" style="margin-top:8px">Nessuna voce ancora. Questa sara' la prima.</div>
    <?php else: ?>
      <ul class="small" style="margin-top:8px">
        <?php foreach ($recent as $r): ?>
          <li>
            <a href="<?=h(entry_url($r))?>"><b><?=h((string)($r['title'] ?: first_line((string)$r['body'], 70)))?></b></a>
            <span class="badge"><?=h((string)$r['source'])?></span>
            <div class="meta"><?=h(fmt_dt((string)$r['created_at']))?> · <?=h((string)$r['slug'])?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
