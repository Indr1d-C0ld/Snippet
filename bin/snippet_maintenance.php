#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("solo da riga di comando\n"); }

/**
 * Manutenzione periodica di snippet (da lanciare via cron / systemd timer).
 * Per ora: ricostruzione integrale del grafo delle correlazioni, che il
 * salvataggio della singola voce aggiorna solo in uscita.
 *
 *   php bin/snippet_maintenance.php [--graph]
 *
 * Legge config.php con la stessa logica della webapp (una cartella sopra
 * webapp/, oppure dentro webapp/).
 */

// Layout repo (../webapp/) o deployment flat (../ accanto a bin/).
$app = is_dir(dirname(__DIR__) . '/webapp') ? dirname(__DIR__) . '/webapp' : dirname(__DIR__);
require $app . '/lib.php';
require $app . '/lib_nlp.php';

$opts = getopt('', ['graph', 'help']);
if (isset($opts['help'])) {
  fwrite(STDERR, "uso: php bin/snippet_maintenance.php [--graph]\n");
  exit(0);
}

$do_graph = isset($opts['graph']) || $argc === 1;

if ($do_graph) {
  $db = db_rw();
  $res = graph_rebuild($db);
  printf(
    "[%s] grafo: %d voci, %d archi in %.2fs\n",
    gmdate('Y-m-d H:i:s'), $res['entries'], $res['edges'], $res['seconds']
  );
}
