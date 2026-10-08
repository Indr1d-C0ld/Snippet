<?php
declare(strict_types=1);

/**
 * snippet - esportazione del diario.
 *
 *   export_zip()        archivio portabile: una voce = un file Markdown con
 *                       metadati (front matter YAML), allegati accanto,
 *                       indice README.md e snippet.json con tutto il resto.
 *                       Leggibile senza snippet, importabile altrove
 *                       (Obsidian, Logseq, un editor qualsiasi).
 *   export_book_html()  il "libro": le voci di un periodo impaginate per la
 *                       stampa (copertina, indice per mese, una voce dopo
 *                       l'altra). Da browser: Stampa -> Salva come PDF.
 *   export_pdf()        lo stesso libro in PDF, generato sul server con
 *                       Chromium headless (se installato).
 */

require_once __DIR__ . '/lib_nlp.php';

/** Voci di un periodo (date locali 'AAAA-MM-GG', estremi inclusi) con metadati. */
function export_entries(SQLite3 $db, ?string $from = null, ?string $to = null, bool $archived = true): array {
  $w = []; $b = [];
  if (!$archived) $w[] = 'archived = 0';
  if ($from) { $w[] = 'created_at >= :f'; $b[':f'] = (new DateTime("$from 00:00:00", tzobj()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
  if ($to)   { $w[] = 'created_at <= :t'; $b[':t'] = (new DateTime("$to 23:59:59", tzobj()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
  $st = $db->prepare('SELECT * FROM entries' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY created_at, id');
  foreach ($b as $k => $v) $st->bindValue($k, $v, SQLITE3_TEXT);
  $out = [];
  $r = $st->execute();
  while ($x = $r->fetchArray(SQLITE3_ASSOC)) $out[(int)$x['id']] = $x + ['tags' => [], 'persons' => [], 'notes' => [], 'attachments' => [], 'links' => [], 'theme' => null];
  if (!$out) return [];
  $in = implode(',', array_keys($out));
  $q = static function (string $sql) use ($db): array {
    $rows = []; $r = $db->query($sql);
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $rows[] = $x;
    return $rows;
  };
  foreach ($q("SELECT et.entry_id, t.name, et.auto FROM entry_tags et JOIN tags t ON t.id = et.tag_id WHERE et.entry_id IN ($in) ORDER BY et.auto, t.name") as $x)
    $out[(int)$x['entry_id']]['tags'][] = ['name' => $x['name'], 'auto' => (int)$x['auto'] === 1];
  foreach ($q("SELECT ep.entry_id, p.name FROM entry_persons ep JOIN persons p ON p.id = ep.person_id WHERE ep.entry_id IN ($in) ORDER BY p.name") as $x)
    $out[(int)$x['entry_id']]['persons'][] = $x['name'];
  foreach ($q("SELECT entry_id, note, created_at FROM notes WHERE entry_id IN ($in) ORDER BY created_at") as $x)
    $out[(int)$x['entry_id']]['notes'][] = $x;
  foreach ($q("SELECT id, entry_id, kind, path, orig_name, mime, transcript FROM attachments WHERE entry_id IN ($in) ORDER BY id") as $x)
    $out[(int)$x['entry_id']]['attachments'][] = $x;
  foreach ($q("SELECT entry_id, url, title, site, excerpt FROM link_previews WHERE entry_id IN ($in) ORDER BY id") as $x)
    $out[(int)$x['entry_id']]['links'][] = $x;
  foreach ($q("SELECT e.id, c.label FROM entries e JOIN clusters c ON c.id = e.cluster WHERE e.id IN ($in)") as $x)
    $out[(int)$x['id']]['theme'] = $x['label'];
  return $out;
}

function export_label(array $e): string {
  $t = trim((string)($e['title'] ?? ''));
  return $t !== '' ? $t : (first_line((string)$e['body'], 80) ?: ('voce ' . $e['id']));
}

/** Nome di file sicuro e leggibile. */
function export_filename(string $s, int $max = 60): string {
  $s = preg_replace('/[^\p{L}\p{N} _\-]+/u', '', $s) ?? '';
  $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
  return mb_substr($s !== '' ? $s : 'voce', 0, $max, 'UTF-8');
}

function export_yaml(string $s): string {
  return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], $s) . '"';
}

/** Una voce in Markdown con front matter. $att_prefix: cartella allegati relativa. */
function export_entry_md(array $e, string $att_prefix = ''): string {
  $local = new DateTime((string)$e['created_at'], new DateTimeZone('UTC'));
  $local->setTimezone(tzobj());
  $fm = ["---",
    'id: ' . (int)$e['id'],
    'slug: ' . $e['slug'],
    'title: ' . export_yaml(export_label($e)),
    'date: ' . $local->format('Y-m-d\TH:i:sP'),
    'source: ' . $e['source']];
  $man = array_column(array_filter($e['tags'], static fn($t) => !$t['auto']), 'name');
  $aut = array_column(array_filter($e['tags'], static fn($t) => $t['auto']), 'name');
  if ($man) $fm[] = 'tags: [' . implode(', ', array_map('export_yaml', $man)) . ']';
  if ($aut) $fm[] = 'auto_tags: [' . implode(', ', array_map('export_yaml', $aut)) . ']';
  if ($e['persons']) $fm[] = 'persons: [' . implode(', ', array_map('export_yaml', $e['persons'])) . ']';
  if ($e['theme']) $fm[] = 'theme: ' . export_yaml((string)$e['theme']);
  if ((int)$e['pinned'] === 1) $fm[] = 'pinned: true';
  if ((int)$e['archived'] === 1) $fm[] = 'archived: true';
  $fm[] = '---';
  $md = implode("\n", $fm) . "\n\n# " . export_label($e) . "\n\n*" . fmt_dt((string)$e['created_at']) . "*\n\n" . trim((string)$e['body']) . "\n";
  if ($e['attachments']) {
    $md .= "\n## Allegati\n\n";
    foreach ($e['attachments'] as $a) {
      $name = (string)($a['orig_name'] ?: basename((string)$a['path']));
      $rel = $att_prefix . $a['path'];
      $md .= ($a['kind'] === 'photo' ? "![$name]($rel)" : "- [$name]($rel)") . "\n";
      if (!empty($a['transcript'])) $md .= "  - trascrizione: " . str_replace("\n", ' ', (string)$a['transcript']) . "\n";
    }
  }
  if ($e['links']) {
    $md .= "\n## Fonti\n\n";
    foreach ($e['links'] as $l) $md .= '- [' . ($l['title'] ?: $l['url']) . '](' . $l['url'] . ')' . ($l['site'] ? ' — ' . $l['site'] : '') . "\n";
  }
  if ($e['notes']) {
    $md .= "\n## Note\n\n";
    foreach ($e['notes'] as $n) $md .= '- *' . fmt_dt((string)$n['created_at']) . '* — ' . str_replace("\n", ' ', (string)$n['note']) . "\n";
  }
  return $md;
}

/**
 * Crea lo ZIP completo in un file temporaneo e ne ritorna il percorso.
 * Il chiamante lo invia e poi lo cancella.
 */
function export_zip(SQLite3 $db): string {
  $entries = export_entries($db);
  $tmp = tempnam(sys_get_temp_dir(), 'snippet-export-');
  $zip = new ZipArchive();
  if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('impossibile creare lo ZIP');
  $root = 'snippet-' . (new DateTime('now', tzobj()))->format('Y-m-d');
  $index = ["# snippet — esportazione del " . (new DateTime('now', tzobj()))->format('d/m/Y H:i'), '',
            count($entries) . ' voci. Ogni voce è un file Markdown con i metadati in testa (front matter YAML);',
            'gli allegati stanno in `attachments/`, tutto il resto anche in `snippet.json`.', ''];
  $month = '';
  $base = realpath((string)cfg()['attachments_dir']);
  $json = [];
  foreach ($entries as $e) {
    $local = (new DateTime((string)$e['created_at'], new DateTimeZone('UTC')))->setTimezone(tzobj());
    $dir = $local->format('Y') . '/' . $local->format('m');
    $file = $dir . '/' . $local->format('Y-m-d') . ' ' . export_filename(export_label($e)) . ' (' . $e['id'] . ').md';
    $zip->addFromString("$root/$file", export_entry_md($e, '../../attachments/'));
    if ($month !== $dir) { $month = $dir; $index[] = "\n## " . $local->format('m/Y') . "\n"; }
    $index[] = '- ' . $local->format('d/m') . ' — [' . export_label($e) . '](' . str_replace(' ', '%20', $file) . ')';
    foreach ($e['attachments'] as $a) {
      $full = $base !== false ? realpath($base . '/' . $a['path']) : false;
      if ($full && str_starts_with($full, $base . DIRECTORY_SEPARATOR) && is_file($full)) {
        $zip->addFile($full, "$root/attachments/" . $a['path']);
      }
    }
    $json[] = [
      'id' => (int)$e['id'], 'slug' => $e['slug'], 'title' => $e['title'], 'body' => $e['body'], 'raw' => $e['raw'],
      'created_at_utc' => $e['created_at'], 'updated_at_utc' => $e['updated_at'], 'source' => $e['source'],
      'pinned' => (int)$e['pinned'] === 1, 'archived' => (int)$e['archived'] === 1,
      'tags' => $e['tags'], 'persons' => $e['persons'], 'theme' => $e['theme'],
      'notes' => $e['notes'], 'links' => $e['links'],
      'attachments' => array_map(static fn($a) => ['kind' => $a['kind'], 'file' => 'attachments/' . $a['path'],
                                                    'name' => $a['orig_name'], 'transcript' => $a['transcript']], $e['attachments']),
    ];
  }
  $zip->addFromString("$root/README.md", implode("\n", $index) . "\n");
  $zip->addFromString("$root/snippet.json", json_encode(['exported_at' => gmdate('c'), 'entries' => $json],
                      JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  $zip->close();
  return $tmp;
}

/** Toglie le righe finali fatte solo di #hashtag (nel libro stanno nei metadati). */
function export_strip_tag_lines(string $body): string {
  $lines = preg_split('/\R/u', rtrim($body)) ?: [];
  while ($lines && preg_match('/^\s*(#[\p{L}\p{N}_\-]+\s*)+$/u', (string)end($lines))) array_pop($lines);
  // hashtag in coda all'ultima frase ("... calmarlo. #incubo #notte")
  if ($lines) $lines[count($lines) - 1] = rtrim(preg_replace('/(\s+#[\p{L}\p{N}_\-]+)+\s*$/u', '', (string)end($lines)) ?? '');
  return implode("\n", $lines);
}

/** Il "libro" di un periodo, come pagina HTML autonoma (CSS incluso). */
function export_book_html(SQLite3 $db, string $from, string $to, string $title): string {
  $entries = export_entries($db, $from, $to, false);
  $mesi = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto',
           'settembre', 'ottobre', 'novembre', 'dicembre'];
  $words = array_sum(array_map(static fn($e) => (int)$e['word_count'], $entries));
  $by_month = [];
  foreach ($entries as $e) {
    $l = (new DateTime((string)$e['created_at'], new DateTimeZone('UTC')))->setTimezone(tzobj());
    $by_month[$l->format('Y-m')][] = $e + ['_local' => $l];
  }
  ob_start(); ?>
<!doctype html>
<html lang="it"><head><meta charset="utf-8"><title><?=h($title)?></title>
<style>
  @page { size: A5; margin: 18mm 16mm 20mm; }
  body { font-family: Georgia, "Times New Roman", serif; font-size: 10.5pt; line-height: 1.55; color: #1c1c1e; background: #fff; margin: 0; }
  .cover { height: 100vh; display: flex; flex-direction: column; justify-content: center; text-align: center; page-break-after: always; }
  .cover h1 { font-size: 26pt; font-weight: normal; margin: 0 0 8mm; letter-spacing: .02em; }
  .cover p { color: #6b7280; margin: 2mm 0; }
  .toc { page-break-after: always; } .toc h2 { font-weight: normal; }
  .toc ul { list-style: none; padding: 0; } .toc li { margin: 1.5mm 0; }
  h2.month { font-weight: normal; font-size: 16pt; border-bottom: 1px solid #d9dade; padding-bottom: 2mm; page-break-before: always; text-transform: capitalize; }
  article { margin: 0 0 9mm; page-break-inside: auto; }
  article h3 { font-size: 12.5pt; margin: 0 0 1mm; page-break-after: avoid; }
  .meta { font-family: system-ui, sans-serif; font-size: 8pt; color: #6b7280; margin-bottom: 3mm; }
  .body p { margin: 0 0 2.5mm; text-align: justify; hyphens: auto; }
  .body ul, .body ol { margin: 0 0 2.5mm 5mm; padding: 0; }
  .body blockquote { border-left: 2px solid #d9dade; margin: 0 0 2.5mm; padding-left: 4mm; color: #4b5563; }
  .body a { color: inherit; text-decoration: none; }
  .body code { font-size: 9pt; }
  .src { font-family: system-ui, sans-serif; font-size: 7.5pt; color: #6b7280; }
  @media screen { body { max-width: 720px; margin: 0 auto; padding: 24px; } .cover { height: auto; min-height: 60vh; }
    .noprint { font-family: system-ui, sans-serif; position: sticky; top: 0; background: #fff; padding: 8px 0; border-bottom: 1px solid #eee; } }
  @media print { .noprint { display: none; } }
</style></head><body>
<div class="noprint">📖 Anteprima del libro · <a href="#" onclick="window.print();return false">Stampa / salva come PDF</a></div>
<section class="cover">
  <h1><?=h($title)?></h1>
  <?php if ($entries): $f = reset($entries); $l = end($entries); ?>
  <p><?=h(fmt_dt((string)$f['created_at'], false))?> — <?=h(fmt_dt((string)$l['created_at'], false))?></p>
  <?php endif; ?>
  <p><?=count($entries)?> voci · <?=number_format($words, 0, ',', '.')?> parole</p>
</section>
<?php if (count($by_month) > 1): ?>
<section class="toc"><h2>Indice</h2><ul>
  <?php foreach ($by_month as $ym => $list): [$y, $m] = explode('-', $ym); ?>
    <li><?=h(ucfirst($mesi[(int)$m]))?> <?=h($y)?> <span class="src">· <?=count($list)?> voci</span></li>
  <?php endforeach; ?>
</ul></section>
<?php endif; ?>
<?php foreach ($by_month as $ym => $list): [$y, $m] = explode('-', $ym); ?>
  <h2 class="month"><?=h($mesi[(int)$m])?> <?=h($y)?></h2>
  <?php foreach ($list as $e): ?>
    <article>
      <h3><?=h(export_label($e))?></h3>
      <div class="meta"><?=h($e['_local']->format('d/m/Y H:i'))?>
        <?php if ($e['persons']): ?> · <?=h(implode(', ', $e['persons']))?><?php endif; ?>
        <?php $man = array_column(array_filter($e['tags'], static fn($t) => !$t['auto']), 'name'); if ($man): ?> · #<?=h(implode(' #', $man))?><?php endif; ?></div>
      <div class="body"><?= entry_render_body(export_strip_tag_lines((string)$e['body'])) ?></div>
      <?php foreach ($e['links'] as $l): if (!$l['title']) continue; ?>
        <div class="src">↳ <?=h((string)$l['title'])?> — <?=h((string)$l['site'])?></div>
      <?php endforeach; ?>
    </article>
  <?php endforeach; ?>
<?php endforeach; ?>
</body></html>
<?php
  return (string)ob_get_clean();
}

/** Percorso di Chromium/Chrome, o '' se assente. */
function export_chromium(): string {
  foreach ((array)(cfg()['chromium_bin'] ?? ['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome']) as $b) {
    if (is_string($b) && is_file($b) && is_executable($b)) return $b;
  }
  return '';
}

/** Il libro in PDF (file temporaneo da inviare e poi cancellare). */
function export_pdf(SQLite3 $db, string $from, string $to, string $title): string {
  $bin = export_chromium();
  if ($bin === '') throw new RuntimeException('Chromium non installato sul server: usa "Stampa / salva come PDF" dal libro.');
  $dir = sys_get_temp_dir() . '/snippet-pdf-' . bin2hex(random_bytes(6));
  if (!mkdir($dir, 0700)) throw new RuntimeException('cartella temporanea non disponibile');
  $html = preg_replace('~<div class="noprint">.*?</div>~s', '', export_book_html($db, $from, $to, $title)) ?? '';
  file_put_contents("$dir/libro.html", $html);
  $pdf = "$dir/libro.pdf";
  // profilo usa-e-getta, nessuna rete: la pagina e' un file locale senza risorse esterne
  $cmd = 'HOME=' . escapeshellarg($dir) . ' timeout 60 ' . escapeshellarg($bin)
       . ' --headless=new --disable-gpu --no-sandbox --no-first-run --disable-extensions'
       . ' --user-data-dir=' . escapeshellarg("$dir/profile")
       . ' --proxy-server=127.0.0.1:9 --no-pdf-header-footer'
       . ' --print-to-pdf=' . escapeshellarg($pdf) . ' ' . escapeshellarg("file://$dir/libro.html") . ' 2>&1';
  exec($cmd, $o, $rc);
  if (!is_file($pdf) || filesize($pdf) < 1000) {
    exec('rm -rf ' . escapeshellarg($dir));
    throw new RuntimeException('generazione PDF non riuscita (codice ' . $rc . ')');
  }
  $out = tempnam(sys_get_temp_dir(), 'snippet-book-');
  rename($pdf, $out);
  exec('rm -rf ' . escapeshellarg($dir));
  return $out;
}
