<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/nav.php';

require_login();
$me = auth_user();

$flash = flash_take();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), ['revoke', 'revoke_all'], true)) {
  if (!csrf_check()) { http_response_code(403); die('CSRF non valido'); }
  $dbw = db_rw();
  if ($_POST['action'] === 'revoke') {
    $st = $dbw->prepare('DELETE FROM auth_tokens WHERE id = :i AND user_id = :u');
    $st->bindValue(':i', (int)($_POST['id'] ?? 0), SQLITE3_INTEGER);
    $st->bindValue(':u', (int)$me['id'], SQLITE3_INTEGER);
    $st->execute();
    flash_set('ok', 'Dispositivo disconnesso.');
  } else {
    $dbw->exec('DELETE FROM auth_tokens WHERE user_id = ' . (int)$me['id']);
    remember_set_cookie('', time() - 3600);
    flash_set('ok', 'Tutti i dispositivi ricordati sono stati disconnessi (anche questo, alla chiusura della sessione).');
  }
  header('Location: profile.php');
  exit;
}

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
    // nuova password: ogni dispositivo ricordato va riautenticato, tranne questo
    $had = remember_selector() !== '';
    $dbw->exec('DELETE FROM auth_tokens WHERE user_id = ' . (int)$me['id']);
    if ($had) remember_issue($dbw, (int)$me['id']);
    flash_set('ok', 'Password aggiornata. Gli altri dispositivi ricordati dovranno accedere di nuovo.');
  } catch (Throwable $e) {
    flash_set('err', $e->getMessage());
  }
  header('Location: profile.php');
  exit;
}

$site = (string)(cfg()['site_name'] ?? 'snippet');
$devices = [];
$r = db_ro()->query('SELECT id, selector, user_agent, ip, created_at, last_used_at, expires_at FROM auth_tokens
                     WHERE user_id = ' . (int)$me['id'] . ' ORDER BY last_used_at DESC');
while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $devices[] = $x;
$mysel = remember_selector();

/** Descrizione breve di un user-agent ("Firefox su Android"). */
function ua_label(string $ua): string {
  $b = preg_match('~Firefox/~', $ua) ? 'Firefox' : (preg_match('~Edg/~', $ua) ? 'Edge'
     : (preg_match('~Chrome/~', $ua) ? 'Chrome' : (preg_match('~Safari/~', $ua) ? 'Safari' : 'browser')));
  $o = preg_match('~Android~', $ua) ? 'Android' : (preg_match('~iPhone|iPad~', $ua) ? 'iOS'
     : (preg_match('~Windows~', $ua) ? 'Windows' : (preg_match('~Mac OS~', $ua) ? 'macOS'
     : (preg_match('~Linux~', $ua) ? 'Linux' : '?'))));
  return "$b su $o";
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
    <b>Dispositivi ricordati</b>
    <div class="meta" style="margin-top:4px">Accessi con "Ricordami": restano validi <?= REMEMBER_DAYS ?> giorni dall'ultimo uso.</div>
    <?php if (!$devices): ?>
      <div class="meta" style="margin-top:8px">Nessuno.</div>
    <?php endif; ?>
    <?php foreach ($devices as $d): ?>
      <div class="row" style="margin-top:8px; gap:8px">
        <span class="small grow"><?=h(ua_label((string)$d['user_agent']))?><?= $d['selector'] === $mysel ? ' <b>(questo)</b>' : '' ?>
          <span class="meta">· ultimo uso <?=h(fmt_dt((string)($d['last_used_at'] ?? $d['created_at'])))?> · da <?=h((string)$d['ip'])?></span></span>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <button class="badge" type="submit">disconnetti</button>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if (count($devices) > 1): ?>
      <form method="post" style="margin-top:10px" onsubmit="return confirm('Disconnettere tutti i dispositivi ricordati?')">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="revoke_all">
        <button class="btn" type="submit">Disconnetti tutti</button>
      </form>
    <?php endif; ?>
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
