#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("solo da riga di comando\n"); }

/**
 * Manutenzione periodica di snippet (systemd timer, ogni notte).
 *
 *   php bin/snippet_maintenance.php                tutto, nell'ordine sotto
 *   php bin/snippet_maintenance.php --transcribe   vocali rimasti da trascrivere
 *   php bin/snippet_maintenance.php --graph        ricostruzione integrale: termini,
 *        TF-IDF, keyword, tag, persone, vettori semantici mancanti, archi, temi
 *   php bin/snippet_maintenance.php --links        anteprime dei link in attesa
 *
 * Trascrizioni e anteprime recuperano cio' che il bot non ha potuto fare sul
 * momento (servizio ML spento, rete assente); la ricostruzione rende
 * simmetrico il grafo e ricalcola i pesi sul diario aggiornato. Legge
 * config.php con la stessa logica della webapp.
 */

define('SNIPPET_NO_SESSION', true);
// Layout repo (../webapp/) o deployment flat (../ accanto a bin/).
$app = is_dir(dirname(__DIR__) . '/webapp') ? dirname(__DIR__) . '/webapp' : dirname(__DIR__);
require $app . '/lib.php';
require $app . '/lib_nlp.php';

$opts = getopt('', ['graph', 'links', 'transcribe', 'help']);
if (isset($opts['help'])) {
  fwrite(STDERR, "uso: php bin/snippet_maintenance.php [--transcribe] [--links] [--graph]\n");
  exit(0);
}
$all = !isset($opts['graph']) && !isset($opts['links']) && !isset($opts['transcribe']);
$ts = static fn() => (new DateTime('now', tzobj()))->format('d/m/Y H:i:s');
$db = db_rw();

/* --- vocali in sospeso da piu' di 30 minuti (il bot non c'e' riuscito) --- */
if ($all || isset($opts['transcribe'])) {
  $done = $fail = 0;
  if (ml_enabled()) {
    $rows = [];
    $r = $db->query("SELECT id, path FROM attachments WHERE transcript_status = 'pending'
                     AND created_at <= datetime('now', '-30 minutes') ORDER BY id LIMIT 20");
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $rows[] = $x;
    $base = realpath((string)cfg()['attachments_dir']);
    foreach ($rows as $x) {
      $full = $base !== false ? realpath($base . '/' . $x['path']) : false;
      if ($full === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
        $db->exec("UPDATE attachments SET transcript_status = 'error' WHERE id = " . (int)$x['id']);
        $fail++;
        continue;
      }
      $ch = curl_init(rtrim((string)cfg()['ml_url'], '/') . '/transcribe');
      $hdr = ['Content-Type: application/octet-stream'];
      if ((string)(cfg()['ml_token'] ?? '') !== '') $hdr[] = 'Authorization: Bearer ' . cfg()['ml_token'];
      curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => (string)file_get_contents($full),
        CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3600]);
      $res = json_decode((string)curl_exec($ch), true);
      curl_close($ch);
      if (is_array($res) && !empty($res['ok']) && trim((string)$res['text']) !== '') {
        attachment_transcript($db, (int)$x['id'], (string)$res['text'], 'manutenzione');
        $done++;
      } else {
        // errore definitivo (audio illeggibile): non riprovare all'infinito
        if (is_array($res) && isset($res['error'])) {
          $db->exec("UPDATE attachments SET transcript_status = 'error' WHERE id = " . (int)$x['id']);
        }
        $fail++;
      }
    }
  }
  printf("[%s] vocali: %d trascritti, %d non riusciti%s\n", $ts(), $done, $fail, ml_enabled() ? '' : ' (servizio ML non configurato)');
}

/* --- ricostruzione integrale (con i vettori semantici mancanti) --- */
if ($all || isset($opts['graph'])) {
  $res = graph_rebuild($db);
  printf(
    "[%s] grafo: %d voci, %d archi, %d temi, vettori aggiornati: %s, in %.2fs\n",
    $ts(), $res['entries'], $res['edges'], $res['clusters'],
    $res['vectors'] < 0 ? 'servizio ML non disponibile' : (string)$res['vectors'], $res['seconds']
  );
}

/* --- anteprime dei link ancora da scaricare ---
   DOPO la ricostruzione: e' lei a registrare gli URL delle voci (anche di
   quelle arrivate senza passare dal bot), quindi cosi' nessun link resta
   in attesa fino alla notte successiva (difetto visto il 08/10/2026). */
if ($all || isset($opts['links'])) {
  $res = links_fetch_pending($db, null, 100);
  printf("[%s] anteprime: %d scaricate, %d non raggiungibili\n", $ts(), $res['ok'], $res['error']);
}
