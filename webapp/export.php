<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/lib_export.php';
require __DIR__ . '/nav.php';
require_login();

/**
 * Esporta: archivio ZIP (Markdown + allegati + JSON), libro stampabile di un
 * periodo, libro in PDF. Le scelte del periodo stanno in GET.
 */

$site = (string)(cfg()['site_name'] ?? 'snippet');
$db = db_ro();
$fmt = (string)($_GET['fmt'] ?? '');

// periodo: anno intero (?year=) oppure da/a (?from=&to=, AAAA-MM-GG)
$years = [];
$r = $db->query("SELECT DISTINCT substr(created_at, 1, 4) y FROM entries ORDER BY y DESC");
while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $years[] = (string)$x[0];
$year = preg_match('/^\d{4}$/', (string)($_GET['year'] ?? '')) ? (string)$_GET['year'] : ($years[0] ?? date('Y'));
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : "$year-01-01";
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : "$year-12-31";
$title = trim((string)($_GET['title'] ?? '')) ?: ('Diario ' . ($from === "$year-01-01" && $to === "$year-12-31" ? $year : fmt_day($from) . ' – ' . fmt_day($to)));
$title = mb_substr($title, 0, 80, 'UTF-8');

function send_file(string $path, string $name, string $type): never {
  header('Content-Type: ' . $type);
  header('Content-Length: ' . (string)filesize($path));
  header('Content-Disposition: attachment; filename="' . $name . '"');
  header('Cache-Control: private, no-store');
  readfile($path);
  @unlink($path);
  exit;
}

try {
  if ($fmt === 'zip') {
    $p = export_zip($db);
    send_file($p, 'snippet-' . (new DateTime('now', tzobj()))->format('Y-m-d') . '.zip', 'application/zip');
  }
  if ($fmt === 'book') {
    header('Content-Type: text/html; charset=utf-8');
    echo export_book_html($db, $from, $to, $title);
    exit;
  }
  if ($fmt === 'pdf') {
    $p = export_pdf($db, $from, $to, $title);
    send_file($p, export_filename($title) . '.pdf', 'application/pdf');
  }
} catch (Throwable $e) {
  flash_set('err', $e->getMessage());
  header('Location: export.php');
  exit;
}

$flash = flash_take();
$n = (int)$db->querySingle('SELECT COUNT(*) FROM entries');
$natt = (int)$db->querySingle('SELECT COUNT(*) FROM attachments');
$has_pdf = export_chromium() !== '';
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<meta name="theme-color" content="#2f6feb">
<link rel="manifest" href="manifest.php">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script src="assets/pwa.js" defer></script>
<title><?=h($site)?> — Esporta</title>
<?php render_header('Esporta', 'export'); ?>

<div class="wrap">
  <?php if ($flash): ?><div class="card"><b><?= $flash[0] === 'ok' ? 'OK:' : 'Errore:' ?></b> <?=h((string)$flash[1])?></div><?php endif; ?>

  <div class="card">
    <b>Archivio completo (ZIP)</b>
    <div class="meta" style="margin-top:4px">
      Tutte le <?=$n?> voci, una per file Markdown con i metadati in testa (data, tag, persone, tema), ordinate in cartelle
      anno/mese; i <?=$natt?> allegati; un indice <code>README.md</code> e <code>snippet.json</code> con tutto in forma strutturata.
      Si apre con qualunque editor e si importa in Obsidian o Logseq. È anche una copia di sicurezza leggibile senza snippet.
    </div>
    <a class="btn" href="export.php?fmt=zip" style="margin-top:10px; display:inline-block">⬇ Scarica lo ZIP</a>
  </div>

  <form class="card" method="get">
    <b>Il libro</b>
    <div class="meta" style="margin-top:4px">Le voci di un periodo impaginate come un libro (A5): copertina, indice per mese, un capitolo per mese.</div>
    <div class="row" style="margin-top:10px">
      <label class="meta">anno
        <select name="year" onchange="this.form.from.value=this.value+'-01-01'; this.form.to.value=this.value+'-12-31'">
          <?php foreach ($years as $y): ?><option value="<?=h($y)?>" <?= $y === $year ? 'selected' : '' ?>><?=h($y)?></option><?php endforeach; ?>
        </select></label>
      <label class="meta">dal <input type="date" name="from" value="<?=h($from)?>"></label>
      <label class="meta">al <input type="date" name="to" value="<?=h($to)?>"></label>
      <input class="grow" name="title" value="<?=h($title)?>" placeholder="titolo">
    </div>
    <div class="btns" style="margin-top:10px">
      <button class="btn" type="submit" name="fmt" value="book" formtarget="_blank">📖 Apri il libro (stampabile)</button>
      <?php if ($has_pdf): ?><button class="btn" type="submit" name="fmt" value="pdf">⬇ Scarica in PDF</button><?php endif; ?>
    </div>
    <?php if (!$has_pdf): ?><div class="meta" style="margin-top:6px">Per il PDF: apri il libro e usa Stampa → Salva come PDF.</div><?php endif; ?>
  </form>
</div>
