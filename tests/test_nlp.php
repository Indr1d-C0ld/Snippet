<?php
declare(strict_types=1);

/* Analisi del testo: stemmer, TF-IDF, auto-tag, persone, correlazioni, temi. */

group('nlp: stemmer italiano (oracolo Snowball)', function () {
  $oracle = require __DIR__ . '/stem_oracle.php';
  $bad = [];
  foreach ($oracle as $w => $want) if (stem_it((string)$w) !== $want) $bad[] = "$w→" . stem_it((string)$w) . " (atteso $want)";
  check(!$bad, count($oracle) . ' parole uguali all\'implementazione di riferimento' . ($bad ? ': ' . implode(', ', array_slice($bad, 0, 5)) : ''));
  eq(stem_it('nazisti'), stem_it('nazista'), 'nazisti e nazista: stessa radice');
  eq(stem_it('Lavoro'), stem_it('lavori'), 'maiuscole e plurali');
});

group('nlp: termini e TF-IDF', function () {
  $t = nlp_terms('I nazisti marciavano. Un nazista parlava di https://esempio.it/nazisti e di lavoro');
  check(isset($t[stem_it('nazista')]) && $t[stem_it('nazista')]['tf'] === 2, 'forme flesse raggruppate (URL esclusi)');
  check(!isset($t[stem_it('marciavano')]), 'le voci verbali non sono termini');
  check(!isset(nlp_terms('va bene, è proprio vero')[stem_it('bene')]), '"bene" è una stopword');

  $db = fresh_db();
  save($db, "Prima :: il mare oggi, mare calmo");
  save($db, "Seconda :: il mare di sera");
  $c = save($db, "Terza :: mare e montagna, montagna, montagna innevata");
  $kw = array_column(nlp_keywords_for($c, nlp_corpus($db, true), 'Terza', 3), 'term');
  eq($kw[0], 'montagna', 'TF-IDF: il termine distintivo batte quello presente ovunque');
});

group('nlp: auto-tag convergenti', function () {
  $db = fresh_db();
  save($db, "Uno :: scritto sul #giardinaggio e sulle rose");
  $b = save($db, "Due :: giardinaggio giardinaggio: oggi potatura delle rose, potatura lunga");
  $auto = array_column(iterator_to_array_rows($db, "SELECT t.name FROM entry_tags et JOIN tags t ON t.id=et.tag_id WHERE et.entry_id=$b AND et.auto=1"), 'name');
  check(in_array('giardinaggio', $auto, true), 'riusa il tag già esistente (convergenza)');
  check(in_array('potatura', $auto, true), 'crea un tag nuovo per un termine ripetuto e distintivo');
  $one = save($db, "Tre :: un pensiero breve sul tramonto");
  eq((int)$db->querySingle("SELECT COUNT(*) FROM entry_tags WHERE entry_id=$one AND auto=1"), 0,
     'nessun tag nuovo da parole citate una volta sola');
});

group('nlp: persone', function () {
  $db = fresh_db();
  $txt = "Oggi Nathan mi ha parlato. Poi Mario Scarpati e Python. Germania e Nazismo. Jamal no #jamal";
  $c = nlp_person_candidates($db, $txt, nlp_persons($db), 6, true);
  check(in_array('Nathan', $c, true) && in_array('Mario Scarpati', $c, true), 'candidati probabili: Nathan, Mario Scarpati');
  check(!in_array('Python', $c, true) && !in_array('Germania', $c, true) && !in_array('Nazismo', $c, true),
        'nessun luogo/software/-ismo fra i probabili');
  check(!in_array('Oggi', nlp_person_candidates($db, $txt, [], 10)), 'parola maiuscola a inizio frase ignorata');

  $pid = person_add($db, 'Mario Scarpati', 'Scarpati');
  $a = save($db, "Uno :: ho visto Mario Scarpati, poi ancora Scarpati");
  $b = save($db, "Due :: #marioscarpati al bar");
  eq((int)$db->querySingle("SELECT mentions FROM entry_persons WHERE entry_id=$a AND person_id=$pid"), 2, 'nome + alias contati senza doppioni');
  eq((int)$db->querySingle("SELECT COUNT(*) FROM entry_persons WHERE entry_id=$b"), 1, 'riconosciuto anche come #hashtag senza spazi');
  check(!in_array('Mario Scarpati', nlp_person_candidates($db, 'parlo con Mario Scarpati', nlp_persons($db))), 'una persona nota non è più candidata');
  person_ignore($db, 'Gianni Bianchi');
  check(!in_array('Gianni Bianchi', nlp_person_candidates($db, 'ieri con Gianni Bianchi', [], 6)), 'un nome scartato non torna');
  eq((int)$db->querySingle("SELECT COUNT(*) FROM entry_tags et JOIN tags t ON t.id=et.tag_id WHERE t.name='scarpati' AND et.auto=1"), 0,
     'il nome di una persona non diventa auto-tag');
});

group('nlp: correlazioni e temi', function () {
  $db = fresh_db();
  $a = save($db, "Orto :: pomodori, zucchine e basilico nell'orto. Irrigazione dei pomodori");
  $b = save($db, "Ancora orto :: i pomodori soffrono, irrigazione da rivedere nell'orto");
  $c = save($db, "Calcio :: partita di calcio allo stadio, gol all'ultimo minuto");
  $d = save($db, "Stadio :: stadio pieno per la partita, calcio spettacolare");
  graph_rebuild($db);
  $lnk = static fn($x, $y) => (int)$db->querySingle("SELECT COUNT(*) FROM links WHERE kind NOT IN ('temporal')
            AND ((src_id=$x AND dst_id=$y) OR (src_id=$y AND dst_id=$x))");
  check($lnk($a, $b) > 0 && $lnk($c, $d) > 0, 'voci sullo stesso argomento correlate');
  eq($lnk($a, $c), 0, 'argomenti diversi non correlati');
  $ca = $db->querySingle("SELECT cluster FROM entries WHERE id=$a");
  check($ca !== null && $ca === $db->querySingle("SELECT cluster FROM entries WHERE id=$b")
        && $ca !== $db->querySingle("SELECT cluster FROM entries WHERE id=$c"), 'due temi distinti');
  $l1 = $db->querySingle('SELECT group_concat(label) FROM clusters');
  graph_rebuild($db);
  eq($db->querySingle('SELECT group_concat(label) FROM clusters'), $l1, 'ricostruzione deterministica');
  check((int)$db->querySingle("SELECT COUNT(*) FROM links WHERE kind='temporal'") >= 1, 'filo temporale fra voci vicine');
});

group('nlp: suggerimenti e fusioni', function () {
  $db = fresh_db();
  save($db, "Uno :: #nazismo e storia");
  save($db, "Due :: il #nazista tedesco");
  save($db, "Tre :: con #mario_scarpati");
  save($db, "Quattro :: con #marioscarpati");
  $m = nlp_tag_merge_candidates($db);
  $pairs = array_map(static fn($x) => $x['from'] . '>' . $x['into'], $m);
  check((bool)array_filter($pairs, static fn($p) => str_contains($p, 'nazis')), 'propone nazista/nazismo');
  check((bool)array_filter($m, static fn($x) => $x['reason'] === 'stesso nome'), 'propone "mario scarpati"/"marioscarpati"');

  $from = (int)$db->querySingle("SELECT id FROM tags WHERE name='nazista'");
  $into = (int)$db->querySingle("SELECT id FROM tags WHERE name='nazismo'");
  eq(tag_merge($db, $from, $into, 'admin'), 1, 'fusione: una voce spostata');
  check(str_contains((string)$db->querySingle("SELECT raw FROM entries WHERE title='Due'"), '#nazismo'), 'fusione: hashtag riscritto nel testo');
  $e = save($db, "Cinque :: ancora #nazista");
  eq((string)$db->querySingle("SELECT group_concat(t.name) FROM entry_tags et JOIN tags t ON t.id=et.tag_id WHERE entry_id=$e"),
     'nazismo', 'alias: un nuovo #nazista va su nazismo');

  $s = save($db, "Sei :: riflessioni sullo storico nazismo europeo");
  check(in_array('nazismo', array_merge(nlp_suggest_tags($db, $s),
        array_column(iterator_to_array_rows($db, "SELECT t.name FROM entry_tags et JOIN tags t ON t.id=et.tag_id WHERE entry_id=$s"), 'name')), true),
        'suggerisce (o applica) il tag esistente pertinente');
});

function iterator_to_array_rows(SQLite3 $db, string $sql): array {
  $out = [];
  $r = $db->query($sql);
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $out[] = $x;
  return $out;
}
