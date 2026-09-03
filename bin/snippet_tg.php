#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("solo da riga di comando\n"); }

/**
 * Gestione della whitelist dei mittenti Telegram (tabella tg_allowed).
 *
 *   php bin/snippet_tg.php --list
 *   php bin/snippet_tg.php --allow <tg_user_id> <username_web>
 *   php bin/snippet_tg.php --deny  <tg_user_id>
 *
 * <username_web> deve corrispondere all'account creato da login.php: e' l'autore
 * con cui verranno registrate le voci arrivate da quel mittente.
 */

$app = is_dir(dirname(__DIR__) . '/webapp') ? dirname(__DIR__) . '/webapp' : dirname(__DIR__);
require $app . '/lib.php';

$a = $argv;
array_shift($a);
$cmd = $a[0] ?? '--list';
$db = db_rw();

switch ($cmd) {
  case '--list':
    $r = $db->query('SELECT tg_user_id, username, added_at FROM tg_allowed ORDER BY added_at');
    $any = false;
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) {
      $any = true;
      printf("%-16s %-20s %s\n", $x['tg_user_id'], $x['username'], $x['added_at']);
    }
    if (!$any) echo "(nessun mittente autorizzato)\n";
    break;

  case '--allow':
    $id = (int)($a[1] ?? 0);
    $user = trim((string)($a[2] ?? ''));
    if ($id <= 0 || $user === '') {
      fwrite(STDERR, "uso: --allow <tg_user_id> <username_web>\n");
      exit(1);
    }
    $known = (int)$db->querySingle(
      "SELECT COUNT(*) FROM users WHERE username = '" . SQLite3::escapeString($user) . "'"
    );
    if ($known === 0) {
      fwrite(STDERR, "attenzione: nessun utente web '$user' (procedo comunque)\n");
    }
    $st = $db->prepare(
      'INSERT INTO tg_allowed(tg_user_id, username) VALUES(:i, :u)
       ON CONFLICT(tg_user_id) DO UPDATE SET username = excluded.username'
    );
    $st->bindValue(':i', $id, SQLITE3_INTEGER);
    $st->bindValue(':u', $user, SQLITE3_TEXT);
    $st->execute();
    echo "ok: $id -> $user\n";
    break;

  case '--deny':
    $id = (int)($a[1] ?? 0);
    if ($id <= 0) { fwrite(STDERR, "uso: --deny <tg_user_id>\n"); exit(1); }
    $db->exec('DELETE FROM tg_allowed WHERE tg_user_id = ' . $id);
    echo "rimosso: $id\n";
    break;

  default:
    fwrite(STDERR, "comandi: --list | --allow <id> <username_web> | --deny <id>\n");
    exit(1);
}
