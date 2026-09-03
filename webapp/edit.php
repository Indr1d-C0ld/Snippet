<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/lib_nlp.php';
require __DIR__ . '/nav.php';
require_login();

$site = (string)(cfg()['site_name'] ?? 'snippet');

$ref = trim((string)($_GET['e'] ?? ''));
if ($ref === '') { http_response_code(400); die('Parametro e mancante.'); }

$db = db_ro();
$eid = entry_resolve_ref($db, $ref);
if ($eid === null) { http_response_code(404); die('Voce non trovata: ' . h($ref)); }

$e = $db->querySingle('SELECT * FROM entries WHERE id=' . $eid, true);
$err = '';
$raw_val = (string)$e['raw'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check()) { http_response_code(403); die('CSRF non valido'); }
  $raw_val = (string)($_POST['raw'] ?? '');
  try {
    if (trim($raw_val) === '') throw new RuntimeException('Il corpo non puo\' essere vuoto.');
    $dbw = db_rw();
    $res = entry_update($dbw, $eid, $raw_val, current_user());
    flash_set('ok', 'Voce aggiornata.');
    header('Location: ' . entry_url($res['slug']));
    exit;
  } catch (Throwable $ex) {
    $err = $ex->getMessage();
  }
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
<title><?=h($site)?> — Modifica <?=h((string)$e['slug'])?></title>

<?php render_header('Modifica ' . (string)$e['slug'], ''); ?>

<div class="wrap">
  <?php if ($err): ?>
    <div class="card"><b>Errore:</b> <?=h($err)?></div>
  <?php endif; ?>

  <form method="post" class="card compose">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="meta">Testo grezzo (direttive, #tag e [[..]] inclusi). Lo slug <code><?=h((string)$e['slug'])?></code> non cambia.</div>
    <textarea name="raw" class="compose-body" autofocus><?=h($raw_val)?></textarea>
    <div class="row" style="margin-top:10px; justify-content:space-between">
      <a class="btn" href="<?=h(entry_url($e))?>">Annulla</a>
      <button class="btn" type="submit">Salva modifiche</button>
    </div>
  </form>
</div>
