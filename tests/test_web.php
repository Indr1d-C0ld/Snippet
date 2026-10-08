<?php
declare(strict_types=1);

/* Web: rendering Markdown sicuro, anteprime dei link (senza rete), alias. */

group('web: markdown leggero', function () {
  $r = entry_render_body("Ciao **mondo** e *tu* e _lei_ con `x*y*z`\n\n- uno\n- due\n\n1. primo\n> citazione\n## Sotto");
  check(str_contains($r, '<strong>mondo</strong>') && str_contains($r, '<em>tu</em>') && str_contains($r, '<em>lei</em>'), 'grassetto e corsivo');
  check(str_contains($r, '<code>x*y*z</code>'), 'il codice non viene formattato');
  check(str_contains($r, '<ul class="md"><li>uno</li>') || str_contains($r, "<ul class=\"md\">\n<li>uno</li>"), 'elenco puntato');
  check(str_contains($r, '<ol class="md">') && str_contains($r, '<blockquote>citazione</blockquote>') && str_contains($r, '<h3 class="md">Sotto</h3>'), 'elenco numerato, citazione, sottotitolo');
  check(substr_count($r, '<p>') === 1, 'paragrafi su riga vuota');
  eq(entry_render_body('2*3*4 e file_name_x'), '<p>2*3*4 e file_name_x</p>', 'nessuna enfasi dentro numeri e parole');

  $u = entry_render_body('vedi https://example.com/a_b_c?x=1&y=2. fine');
  check(str_contains($u, 'href="https://example.com/a_b_c?x=1&amp;y=2"') && str_contains($u, '</a>. fine'), 'URL cliccabile, & riescapata, punto finale fuori');
  check(!str_contains($u, '<em>'), 'gli _ di un URL non diventano corsivo');
  check(str_contains(entry_render_body('#viaggio e #mare_aperto'), 'href="tags.php?tag=mare%20aperto"'), 'hashtag -> pagina del tag');
  check(!str_contains(entry_render_body("l'altro"), 'tags.php'), 'l\'apostrofo (&#039;) non e\' un hashtag');
  check(str_contains(entry_render_body('vedi [[12]]'), 'href="entry.php?e=12"'), 'backlink [[..]]');
});

group('web: markdown non apre XSS', function () {
  foreach ([
    '<script>alert(1)</script>',
    '<img src=x onerror=alert(1)>',
    '**<b onmouseover=alert(1)>x</b>**',
    'javascript:alert(1)',
    'http://x.it/"onmouseover="alert(1)',
    'https://e.com/<script>',
    '`<script>`',
    '[[1"><script>]]',
    '#"><script>alert(1)</script>',
    "- <svg/onload=alert(1)>\n> <iframe src=x>",
  ] as $p) {
    $r = entry_render_body($p);
    // via i soli tag che il renderer puo' produrre; non deve restare alcun '<'
    $rest = preg_replace('~</?(p|br|strong|em|code|ul|ol|li|blockquote|h3)( class="md")?>'
      . '|<a href="(https?://[^"<>]*|entry\.php\?e=[^"<>]*|tags\.php\?tag=[^"<>]*)"( class="hashtag")?( target="_blank" rel="noopener noreferrer nofollow")?>|</a>~', '', $r);
    check(!str_contains((string)$rest, '<') && !str_contains($r, 'href="javascript'), 'neutralizzato: ' . $p);
  }
});

group('web: anteprime link (offline)', function () {
  eq(links_extract('vedi https://a.it/x, e (https://b.it/y). Poi http://c.it'), ['https://a.it/x', 'https://b.it/y', 'http://c.it'], 'estrazione URL, punteggiatura esclusa');
  foreach (['http://127.0.0.1/', 'http://10.1.2.3/', 'http://192.168.1.1/', 'http://169.254.169.254/', 'http://[::1]/', 'ftp://x.it/', 'http://x.it:22/'] as $u) {
    $blocked = false;
    try { link_http_get($u); } catch (Throwable $e) { $blocked = true; }
    check($blocked, "anti-SSRF: $u bloccato");
  }
  $html = '<html><head><title>T</title><meta property="og:title" content="Titolo OG"><meta name="description" content="Desc"></head>'
        . '<body><nav>menu menu menu menu menu menu menu menu menu menu menu menu</nav><article><p>' . str_repeat('Testo principale dell\'articolo. ', 4) . '</p>'
        . '<script>var x="non deve comparire nemmeno per sbaglio, per nulla";</script></article></body></html>';
  $p = link_parse_html($html, 'https://sito.it/a');
  eq($p['title'], 'Titolo OG', 'titolo da og:title');
  check(str_contains($p['excerpt'], 'Testo principale') && !str_contains($p['excerpt'], 'non deve') && !str_contains($p['excerpt'], 'menu'), 'estratto senza script/menu');

  $db = fresh_db();
  $id = save($db, 'Notizia :: leggo https://esempio.it/a e basta');
  eq((int)$db->querySingle("SELECT COUNT(*) FROM link_previews WHERE entry_id=$id AND status='pending'"), 1, 'URL registrato in attesa');
  entry_update($db, $id, 'Notizia :: ora niente link', 'admin');
  eq((int)$db->querySingle("SELECT COUNT(*) FROM link_previews WHERE entry_id=$id"), 0, 'URL tolto dal testo -> anteprima rimossa');
});

group('web: aggiunte e trascrizioni', function () {
  $db = fresh_db();
  $id = save($db, ENTRY_PLACEHOLDER);
  $db->exec("INSERT INTO attachments(entry_id, kind, path, transcript_status) VALUES($id, 'voice', '$id/v.oga', 'pending')");
  $aid = (int)$db->lastInsertRowID();
  attachment_transcript($db, $aid, 'Oggi ho parlato con il dentista del mio dente', 'admin');
  eq((string)$db->querySingle("SELECT raw FROM entries WHERE id=$id"), 'Oggi ho parlato con il dentista del mio dente', 'la trascrizione sostituisce il segnaposto');
  eq((string)$db->querySingle("SELECT transcript_status FROM attachments WHERE id=$aid"), 'done', 'stato: trascritto');
  entry_append($db, $id, 'E poi sono tornato a casa.', 'admin');
  $raw = (string)$db->querySingle("SELECT raw FROM entries WHERE id=$id");
  check(str_contains($raw, '_(aggiunto il ') && str_ends_with($raw, 'E poi sono tornato a casa.'), 'aggiunta datata in coda');
  check((int)$db->querySingle("SELECT COUNT(*) FROM entries_fts WHERE entries_fts MATCH 'dentista'") === 1, 'il parlato trascritto e\' cercabile');
});
