<?php
declare(strict_types=1);

/**
 * snippet - test automatici (nessuna dipendenza esterna).
 *
 *   php tests/run.php            tutti i test
 *   php tests/run.php nlp        solo i gruppi il cui nome contiene "nlp"
 *
 * Ogni esecuzione lavora su un DB temporaneo creato da zero (schema.sql +
 * migrazioni) in una cartella temporanea: non tocca mai i dati reali.
 * Esce con codice 1 se almeno un controllo fallisce.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$ROOT = dirname(__DIR__);
$WEB  = is_file($ROOT . '/webapp/lib.php') ? $ROOT . '/webapp' : $ROOT;

$TMP = sys_get_temp_dir() . '/snippet-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($TMP . '/attachments', 0775, true);
mkdir($TMP . '/logs', 0775, true);
file_put_contents($TMP . '/config.php', '<?php return ' . var_export([
  'db_path' => $TMP . '/test.db',
  'attachments_dir' => $TMP . '/attachments',
  'log_dir' => $TMP . '/logs',
  'ingest_token' => 'test-token',
  'ingest_allow_ip' => ['127.0.0.1'],
  'timezone' => 'Europe/Rome',
  'site_name' => 'snippet-test',
  'ml_url' => '',                 // niente servizio ML nei test
  'link_fetch' => false,          // niente rete nei test
], true) . ';');
putenv('SNIPPET_CONFIG=' . $TMP . '/config.php');

define('SNIPPET_NO_SESSION', true);
require $WEB . '/lib.php';
require $WEB . '/lib_nlp.php';

register_shutdown_function(static function () use ($TMP) {
  exec('rm -rf ' . escapeshellarg($TMP));
});

/* ---------------------------------------------------------------- harness */

$FILTER = $argv[1] ?? '';
$PASS = $FAIL = 0;
$GROUP = '';

function group(string $name, callable $fn): void {
  global $FILTER, $GROUP;
  if ($FILTER !== '' && stripos($name, $FILTER) === false) return;
  $GROUP = $name;
  echo "\n\033[1m$name\033[0m\n";
  try {
    $fn();
  } catch (Throwable $e) {
    check(false, 'eccezione: ' . get_class($e) . ': ' . $e->getMessage()
          . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
  }
}

function check(bool $ok, string $what): void {
  global $PASS, $FAIL;
  if ($ok) { $PASS++; echo "  \033[32m✓\033[0m $what\n"; }
  else     { $FAIL++; echo "  \033[31m✗ $what\033[0m\n"; }
}

function eq($got, $want, string $what): void {
  $ok = $got === $want;
  check($ok, $what . ($ok ? '' : '  → atteso ' . json_encode($want, JSON_UNESCAPED_UNICODE)
                                  . ', ottenuto ' . json_encode($got, JSON_UNESCAPED_UNICODE)));
}

function fresh_db(): SQLite3 {
  // ogni gruppo che lo chiede riparte da un DB vuoto
  $p = cfg()['db_path'];
  foreach ([$p, "$p-wal", "$p-shm"] as $f) if (is_file($f)) unlink($f);
  $db = new SQLite3($p);
  $db->enableExceptions(true);
  $db->exec((string)file_get_contents(schema_path()));
  db_migrate($db);
  $db->exec('PRAGMA foreign_keys=ON');
  return $db;
}

function save(SQLite3 $db, string $raw, array $extra = []): int {
  return entry_save($db, ['raw' => $raw, 'author' => 'admin'] + $extra)['id'];
}

/* ---------------------------------------------------------------- gruppi */

foreach (glob(__DIR__ . '/test_*.php') ?: [] as $f) require $f;

/* ---------------------------------------------------------------- esito */

echo "\n" . ($FAIL === 0 ? "\033[32m" : "\033[31m")
   . "$PASS superati, $FAIL falliti\033[0m\n";
exit($FAIL === 0 ? 0 : 1);
