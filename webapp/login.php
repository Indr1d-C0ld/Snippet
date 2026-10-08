<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Gia' autenticato.
if (auth_user() !== null) {
  header('Location: compose.php');
  exit;
}

/** Consente solo redirect verso una pagina .php locale (anti open-redirect). */
function safe_next(string $n): string {
  if (preg_match('~([A-Za-z0-9_]+\.php(?:\?[^#\s]*)?)$~', trim($n), $m)) {
    return $m[1];
  }
  return 'compose.php';
}

$dbw = db_rw();
users_ensure($dbw);
$nUsers = (int)$dbw->querySingle('SELECT COUNT(*) FROM users');
$bootstrap = ($nUsers === 0);

$err  = '';
$ip   = login_client_ip();
$next = safe_next((string)($_POST['next'] ?? $_GET['next'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check()) {
    http_response_code(403);
    die('CSRF non valido');
  }

  $username = trim((string)($_POST['username'] ?? ''));
  $password = (string)($_POST['password'] ?? '');

  // Throttling per IP: oltre LOGIN_MAX_FAIL tentativi falliti nella finestra
  // l'IP resta bloccato e le credenziali non vengono nemmeno valutate.
  $wait = login_locked_for($dbw, $ip);
  if ($wait > 0) {
    $err = 'Troppi tentativi falliti. Riprova fra ' . (int)ceil($wait / 60) . ' minuti.';
  }

  if ($bootstrap) {
    $password2 = (string)($_POST['password2'] ?? '');
    if (!preg_match('~^[A-Za-z0-9._-]{2,32}$~', $username)) {
      $err = 'Username non valido (2-32 caratteri: lettere, cifre, . _ -).';
    } elseif (strlen($password) < 8) {
      $err = 'Password troppo corta (minimo 8 caratteri).';
    } elseif ($password !== $password2) {
      $err = 'Le due password non coincidono.';
    } else {
      $st = $dbw->prepare(
        "INSERT INTO users(username, password_hash, role, created_by)
         VALUES(:u, :h, 'admin', '(bootstrap)')"
      );
      $st->bindValue(':u', $username, SQLITE3_TEXT);
      $st->bindValue(':h', password_hash($password, PASSWORD_DEFAULT), SQLITE3_TEXT);
      $st->execute();
      $uid = (int)$dbw->lastInsertRowID();

      session_regenerate_id(true);
      $_SESSION['uid']   = $uid;
      $_SESSION['uname'] = $username;
      $_SESSION['role']  = 'admin';
      $dbw->exec('UPDATE users SET last_login_at=datetime(\'now\') WHERE id=' . $uid);
      if (!empty($_POST['remember'])) remember_issue($dbw, $uid);

      header('Location: compose.php');
      exit;
    }
  } elseif ($err === '') {
    $st = $dbw->prepare(
      'SELECT id, username, password_hash, role, disabled FROM users WHERE username = :u'
    );
    $st->bindValue(':u', $username, SQLITE3_TEXT);
    $row = $st->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$row || (int)$row['disabled'] === 1
        || !password_verify($password, (string)$row['password_hash'])) {
      $err = 'Credenziali non valide.';
      login_note_fail($dbw, $ip);
      usleep(300000);
    } else {
      login_note_ok($dbw, $ip);
      session_regenerate_id(true);
      $_SESSION['uid']   = (int)$row['id'];
      $_SESSION['uname'] = (string)$row['username'];
      $_SESSION['role']  = (string)$row['role'];
      $dbw->exec('UPDATE users SET last_login_at=datetime(\'now\') WHERE id=' . (int)$row['id']);
      if (!empty($_POST['remember'])) remember_issue($dbw, (int)$row['id']);

      if (password_needs_rehash((string)$row['password_hash'], PASSWORD_DEFAULT)) {
        $u = $dbw->prepare('UPDATE users SET password_hash=:h WHERE id=:id');
        $u->bindValue(':h', password_hash($password, PASSWORD_DEFAULT), SQLITE3_TEXT);
        $u->bindValue(':id', (int)$row['id'], SQLITE3_INTEGER);
        $u->execute();
      }

      header('Location: ' . safe_next((string)($_POST['next'] ?? '')));
      exit;
    }
  }
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
<title><?=h($site)?> — <?= $bootstrap ? 'Primo accesso' : 'Accesso' ?></title>

<header>
  <b><?=h($site)?></b>
  <div class="meta"><?= $bootstrap ? 'Creazione dell\'account' : 'Accesso riservato' ?></div>
</header>

<div class="wrap">
  <?php if ($err): ?>
    <div class="card"><b>Errore:</b> <?=h($err)?></div>
  <?php endif; ?>

  <form method="post" class="card" autocomplete="off">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="next" value="<?=h($next)?>">

    <?php if ($bootstrap): ?>
      <div class="meta" style="margin-bottom:10px">
        Non esiste ancora nessun account. Crea qui il tuo: snippet e' mono-utente.
      </div>
    <?php endif; ?>

    <div class="meta">Username</div>
    <input name="username" autofocus required
           value="<?=h((string)($_POST['username'] ?? ''))?>"
           <?= $bootstrap ? 'pattern="[A-Za-z0-9._-]{2,32}"' : '' ?>>

    <div class="meta" style="margin-top:8px">Password<?= $bootstrap ? ' (min 8 caratteri)' : '' ?></div>
    <input type="password" name="password" required <?= $bootstrap ? 'minlength="8"' : '' ?>>

    <?php if ($bootstrap): ?>
      <div class="meta" style="margin-top:8px">Ripeti password</div>
      <input type="password" name="password2" required minlength="8">
    <?php endif; ?>

    <label class="meta" style="display:flex;align-items:center;gap:8px;margin-top:10px">
      <input type="checkbox" name="remember" value="1" checked style="width:auto">
      Ricordami su questo dispositivo per <?= REMEMBER_DAYS ?> giorni
    </label>

    <div style="margin-top:12px">
      <button class="btn" type="submit"><?= $bootstrap ? 'Crea account' : 'Accedi' ?></button>
    </div>
  </form>
</div>
