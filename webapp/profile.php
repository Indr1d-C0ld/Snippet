<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/nav.php';

require_login();
$me = auth_user();

$flash = flash_take();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check()) { http_response_code(403); die('CSRF non valido'); }

  $cur  = (string)($_POST['current'] ?? '');
  $new  = (string)($_POST['new'] ?? '');
  $new2 = (string)($_POST['new2'] ?? '');

  try {
    $dbw = db_rw();
    $hash = (string)$dbw->querySingle(
      'SELECT password_hash FROM users WHERE id=' . (int)$me['id']
    );
    if (!$hash || !password_verify($cur, $hash)) {
      throw new RuntimeException('Password attuale errata.');
    }
    if (strlen($new) < 8) {
      throw new RuntimeException('La nuova password deve avere almeno 8 caratteri.');
    }
    if ($new !== $new2) {
      throw new RuntimeException('Le due nuove password non coincidono.');
    }
    $st = $dbw->prepare('UPDATE users SET password_hash=:h WHERE id=:id');
    $st->bindValue(':h', password_hash($new, PASSWORD_DEFAULT), SQLITE3_TEXT);
    $st->bindValue(':id', (int)$me['id'], SQLITE3_INTEGER);
    $st->execute();
    flash_set('ok', 'Password aggiornata.');
  } catch (Throwable $e) {
    flash_set('err', $e->getMessage());
  }
  header('Location: profile.php');
  exit;
}

$site = (string)(cfg()['site_name'] ?? 'snippet');
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<meta name="theme-color" content="#2f6feb">
<link rel="manifest" href="manifest.php">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script src="assets/pwa.js" defer></script>
<title><?=h($site)?> — Profilo</title>

<?php render_header('Profilo', 'profile'); ?>

<div class="wrap">
  <?php if ($flash): ?>
    <div class="card"><b><?= $flash[0] === 'ok' ? 'OK:' : 'Errore:' ?></b> <?=h((string)$flash[1])?></div>
  <?php endif; ?>

  <div class="card">
    <b>Account</b>
    <div class="meta" style="margin-top:6px">
      utente: <?=h((string)$me['username'])?> · creato: <?=h(fmt_dt((string)($me['created_at'] ?? '')))?>
    </div>
  </div>

  <div class="card">
    <b>Cambia password</b>
    <form method="post" style="margin-top:10px" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <div class="meta">Password attuale</div>
      <input type="password" name="current" required>
      <div class="meta" style="margin-top:8px">Nuova password (min 8 caratteri)</div>
      <input type="password" name="new" minlength="8" required>
      <div class="meta" style="margin-top:8px">Ripeti nuova password</div>
      <input type="password" name="new2" minlength="8" required>
      <div style="margin-top:12px"><button class="btn" type="submit">Aggiorna</button></div>
    </form>
  </div>
</div>
