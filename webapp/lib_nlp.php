<?php
declare(strict_types=1);

/**
 * snippet - pipeline NLP e persistenza delle voci.
 *
 * UNICA implementazione di: parsing direttive, termini e keyword (TF-IDF su
 * radici italiane), auto-tag, persone, correlazioni/backlink, temi.
 * Usata sia dal salvataggio web (compose.php/edit.php) sia dall'ingest
 * Telegram (api/ingest.php) e dal bot (api/bot.php).
 *
 * Dal 08/10/2026 (blocco A della roadmap):
 *  - i termini sono raggruppati per radice (lib_stem.php): "nazista" e
 *    "nazisti" contano come lo stesso termine;
 *  - le keyword si pesano con TF-IDF sull'intero diario: una parola presente
 *    ovunque ("bene", "cosa") pesa poco, una che distingue la voce pesa molto;
 *  - gli auto-tag preferiscono i tag che hai GIA' usato (vocabolario che
 *    converge) e ne creano di nuovi solo per termini ripetuti e distintivi;
 *  - le correlazioni combinano similarita' del testo (coseno TF-IDF), tag
 *    condivisi, persone condivise e, se disponibile, il significato
 *    (embedding locale, lib_ml.php).
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib_stem.php';
require_once __DIR__ . '/lib_ml.php';
require_once __DIR__ . '/lib_links.php';

/* ============================  Stoplist  ============================ */

/** Mappa [parola => true]: stopwords.php + tabella stopwords_custom. */
function nlp_stopwords(): array {
  static $sw = null;
  if ($sw !== null) return $sw;

  $f = __DIR__ . '/stopwords.php';
  $list = is_file($f) ? require $f : [];
  $sw = array_fill_keys(array_map(
    static fn($w) => mb_strtolower((string)$w, 'UTF-8'),
    is_array($list) ? $list : []
  ), true);

  try {
    $res = db_ro()->query('SELECT word FROM stopwords_custom');
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
      $w = mb_strtolower(trim((string)$r['word']), 'UTF-8');
      if ($w !== '') $sw[$w] = true;
    }
  } catch (Throwable $e) {
    // tabella non ancora presente: si prosegue con la sola stopwords.php
  }
  return $sw;
}

/* ============================  Keyword  ============================ */

/**
 * Euristica morfologica: la parola sembra una voce verbale (da escludere dalle
 * keyword/auto-tag). Copre infiniti, gerundi e desinenze coniugate non
 * ambigue; una piccola whitelist protegge i sostantivi che finiscono come
 * verbi (comando, affare, militare, potere/dovere/sapere come nomi, ...).
 */
function nlp_looks_like_verb(string $w): bool {
  static $keep = null;
  if ($keep === null) {
    $keep = array_fill_keys([
      'comando', 'brando', 'fondo', 'mondo', 'tondo', 'biondo', 'secondo', 'profondo',
      'affare', 'nucleare', 'militare', 'popolare', 'familiare', 'similare', 'solare',
      'lunare', 'volgare', 'polare', 'scalare', 'quadro', 'ministro', 'registro',
      'potere', 'dovere', 'sapere', 'piacere', 'parere', 'volere', 'avvenire', 'divenire', 'genere',
      'carattere', 'pensiero', 'sentiero', 'mistero', 'materiale', 'sedere',
      // nomi in -uto
      'minuto', 'rifiuto', 'statuto', 'saluto', 'istituto', 'attributo',
      'contributo', 'tributo', 'velluto', 'sostituto',
      // nomi comuni in -ato / -avo
      'risultato', 'certificato', 'candidato', 'avvocato', 'mercato', 'senato',
      'comitato', 'sindacato', 'delegato', 'trattato', 'apparato', 'magistrato',
      'laureato', 'associato', 'dottorato', 'campionato', 'bucato', 'peccato',
      'ducato', 'formato', 'contratto', 'ritratto', 'palato', 'dato', 'stato',
      'schiavo', 'bravo',
      // nomi/aggettivi protetti dalle regole su futuri e avverbi
      'supremo', 'estremo', 'samurai', 'parete', 'clemente', 'veemente', 'demente',
      'sorridente', 'presidente', 'dirigente', 'corrente', 'ambiente',
    ], true);
  }
  if (isset($keep[$w])) return false;
  $len = mb_strlen($w, 'UTF-8');
  if ($len >= 6 && preg_match('/(ando|endo)$/u', $w)) return true;                 // gerundio
  if ($len >= 5 && preg_match('/(are|ere|ire|arsi|ersi|irsi)$/u', $w)
      && !preg_match('/iere$/u', $w)) return true;   // infinito (-iere e' nominale:
                                                     // mestiere, quartiere, bicchiere)
  if ($len >= 7 && preg_match('/(ar|er|ir)(la|lo|li|le|ne|mi|ti|ci|vi|si|gli)$/u', $w)) return true; // infinito + enclitico
  if ($len >= 5 && preg_match('/(iamo)$/u', $w)) return true;                       // 1a pl.
  if ($len >= 6 && preg_match('/uto$/u', $w)) return true;                          // participio -uto
  if ($len >= 5 && preg_match('/ono$/u', $w)                                        // 3a pl. presente
      && !preg_match('/(fono|trono|tono|abbandono|patrono|autoctono|contorno|frastuono)$/u', $w)) {
    return true;
  }
  // imperfetto -ava/-eva/-avo/-evo (quasi sempre verbale); si evita -iva/-ivo
  // perche' e' soprattutto un suffisso di nomi/aggettivi (iniziativa,
  // prospettiva, obiettivo, motivo...).
  if ($len >= 6 && preg_match('/(ava|eva|avo|evo)$/u', $w)
      && !preg_match('/(caterva|larva|malva|selva|belva)$/u', $w)) {
    return true;
  }
  // participio -ato (>=7 lett.), salvo i nomi comuni della whitelist sopra.
  if ($len >= 7 && preg_match('/ato$/u', $w)) return true;
  // avverbi in -mente: non sono keyword di contenuto (nuovamente, relativamente).
  // >= 9 lettere per non toccare clemente/veemente/demente.
  if ($len >= 9 && preg_match('/mente$/u', $w)) return true;
  // futuro semplice: -ra'/-ro'/-rai/-remo/-rete/-ranno
  if ($len >= 5 && preg_match('/(rà|rò)$/u', $w)) return true;
  if ($len >= 5 && preg_match('/rai$/u', $w)) return true;
  if ($len >= 6 && preg_match('/(remo|ranno)$/u', $w)) return true;
  if ($len >= 7 && preg_match('/rete$/u', $w)) return true;
  // (niente -asti/-esti/-isti: il passato remoto alla 2a persona e' rarissimo,
  //  mentre nazisti, artisti, turisti, contrasti, pasti sono nomi comuni)
  if (preg_match('/(avano?|evano?|ivano?|arono|erono|irono|assero|essero|issero|erebbero|irebbero|eremmo|iremmo|erete|irete|avate|evate|ivate|ammo|emmo|immo)$/u', $w)) {
    return true;
  }
  return false;
}

/**
 * Parole piu' frequenti del testo, al netto della stoplist e delle voci
 * verbali (nlp_looks_like_verb). Minuscolo, solo lettere, lunghezza >= 4;
 * si scartano gli hapax (1 occorrenza) con ripiego all'elenco completo sui
 * testi brevi. Ritorna una lista ordinata di ['term' => string, 'freq' => int].
 */
function nlp_extract_keywords(string $text, int $limit = 8): array {
  $text = mb_strtolower($text, 'UTF-8');
  $text = preg_replace('/[^\p{L}\s]/u', ' ', $text) ?? '';
  $text = preg_replace('/\s+/', ' ', $text) ?? '';

  $stop = nlp_stopwords();
  $freq = [];
  foreach (explode(' ', $text) as $w) {
    $w = trim($w);
    if ($w === '' || mb_strlen($w, 'UTF-8') < 4) continue;
    if (isset($stop[$w]) || nlp_looks_like_verb($w)) continue;
    $freq[$w] = ($freq[$w] ?? 0) + 1;
  }
  arsort($freq);

  $strong = array_filter($freq, static fn($c) => $c >= 2);
  $pool = count($strong) >= $limit ? $strong : $freq;

  $out = [];
  foreach (array_slice($pool, 0, $limit, true) as $term => $c) {
    $out[] = ['term' => (string)$term, 'freq' => (int)$c];
  }
  return $out;
}

/* ============================  Termini (TF-IDF)  ============================ */

/** Parole minuscole (solo lettere) del testo, URL esclusi. */
function nlp_words(string $text): array {
  $text = preg_replace('~https?://\S+~u', ' ', $text) ?? $text;
  return preg_split('/[^\p{L}]+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/**
 * Termini di contenuto del testo raggruppati per radice:
 *   [stem => ['tf' => occorrenze, 'form' => forma reale piu' frequente]].
 * Stessi filtri delle keyword (stoplist, voci verbali, lunghezza >= 4).
 * $skip: parole minuscole da ignorare (i nomi delle persone note, che hanno
 * una loro dimensione e non devono diventare tag).
 */
function nlp_terms(string $text, array $skip = []): array {
  $stop = nlp_stopwords();
  $forms = [];
  foreach (nlp_words($text) as $w) {
    if (isset($skip[$w]) || mb_strlen($w, 'UTF-8') < 4 || isset($stop[$w]) || nlp_looks_like_verb($w)) continue;
    $st = stem_it($w);
    if (mb_strlen($st, 'UTF-8') < 3 || isset($stop[$st])) continue;
    $forms[$st][$w] = ($forms[$st][$w] ?? 0) + 1;
  }
  $out = [];
  foreach ($forms as $st => $fs) {
    $best = ''; $bc = -1;
    foreach ($fs as $f => $c) {
      $f = (string)$f;
      if ($c > $bc || ($c === $bc && strcmp($f, $best) < 0)) { $best = $f; $bc = $c; }
    }
    $out[(string)$st] = ['tf' => array_sum($fs), 'form' => $best];
  }
  return $out;
}

function nlp_store_terms(SQLite3 $db, int $id, array $terms): void {
  $db->exec('DELETE FROM entry_terms WHERE entry_id = ' . $id);
  $st = $db->prepare('INSERT INTO entry_terms(entry_id, stem, form, tf) VALUES(:e, :s, :f, :t)');
  foreach ($terms as $stem => $x) {
    $st->reset();
    $st->bindValue(':e', $id, SQLITE3_INTEGER);
    $st->bindValue(':s', (string)$stem, SQLITE3_TEXT);
    $st->bindValue(':f', $x['form'], SQLITE3_TEXT);
    $st->bindValue(':t', $x['tf'], SQLITE3_INTEGER);
    $st->execute();
  }
}

/**
 * Modello TF-IDF dell'intero diario, da entry_terms. Per ogni voce il vettore
 * pesato w = (1 + ln tf) * idf, la norma, e un indice inverso (radice ->
 * voci) per calcolare le similarita' senza confrontare ogni coppia.
 *   idf = ln((N + 1) / (df + 1)) + 1     (liscio: mai zero, mai negativo)
 * Memoizzato per richiesta: $refresh = true dopo aver scritto entry_terms.
 */
function nlp_corpus(SQLite3 $db, bool $refresh = false): array {
  static $c = null;
  if ($c !== null && !$refresh) return $c;
  $tf = $form = $df = $gform = [];
  $r = $db->query('SELECT entry_id, stem, form, tf FROM entry_terms');
  while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) {
    $id = (int)$x[0]; $s = (string)$x[1]; $t = (int)$x[3];
    $tf[$id][$s] = $t;
    $form[$id][$s] = (string)$x[2];
    $df[$s] = ($df[$s] ?? 0) + 1;
    $gform[$s][(string)$x[2]] = ($gform[$s][(string)$x[2]] ?? 0) + $t;
  }
  $N = max(1, (int)$db->querySingle('SELECT COUNT(*) FROM entries'));
  $idf = [];
  foreach ($df as $s => $d) $idf[$s] = log(($N + 1) / ($d + 1)) + 1;
  $w = $norm = $post = [];
  foreach ($tf as $id => $terms) {
    $n2 = 0.0;
    foreach ($terms as $s => $t) {
      $x = (1 + log($t)) * $idf[$s];
      $w[$id][$s] = $x;
      $post[$s][$id] = $x;
      $n2 += $x * $x;
    }
    $norm[$id] = sqrt($n2);
  }
  return $c = compact('N', 'tf', 'form', 'df', 'idf', 'w', 'norm', 'post', 'gform');
}

/** Forma piu' usata di una radice in tutto il diario (per etichette). */
function nlp_display_form(array $corpus, string $stem): string {
  $fs = $corpus['gform'][$stem] ?? [];
  if (!$fs) return $stem;
  arsort($fs);
  return (string)array_key_first($fs);
}

/**
 * Keyword di una voce dal modello: radici ordinate per TF-IDF, con un premio
 * per i termini presenti nel titolo. Ritorna una lista di
 * ['stem', 'term' (forma reale), 'freq', 'score'].
 */
function nlp_keywords_for(int $id, array $corpus, ?string $title, int $limit = 8): array {
  $tw = array_fill_keys(nlp_words((string)$title), true);
  $rows = [];
  foreach ($corpus['w'][$id] ?? [] as $s => $w) {
    $form = $corpus['form'][$id][$s];
    $rows[] = ['stem' => (string)$s, 'term' => $form, 'freq' => $corpus['tf'][$id][$s],
               'score' => round($w * (isset($tw[$form]) ? 1.5 : 1.0), 4)];
  }
  usort($rows, static fn($a, $b) => [$b['score'], $b['freq'], $a['stem']] <=> [$a['score'], $a['freq'], $b['stem']]);
  return array_slice($rows, 0, $limit);
}

/**
 * Indice dei tag gia' in uso su ALTRE voci, per radice della prima parola:
 *   [stem => [['id','name','kind','uses','stems' => [...]], ...]]
 */
function nlp_tag_index(SQLite3 $db, int $exclude_entry = 0): array {
  $idx = [];
  $r = $db->query('SELECT t.id, t.name, t.kind, COUNT(et.entry_id) uses
                   FROM tags t JOIN entry_tags et ON et.tag_id = t.id
                   WHERE et.entry_id <> ' . $exclude_entry . ' GROUP BY t.id');
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
    $stems = array_map('stem_it', preg_split('/[\s_\-]+/u', (string)$x['name'], -1, PREG_SPLIT_NO_EMPTY) ?: []);
    if (!$stems) continue;
    $x['stems'] = $stems;
    $idx[$stems[0]][] = $x;
  }
  try {
    $r = $db->query('SELECT a.alias, t.id, t.name, t.kind,
                       (SELECT COUNT(*) FROM entry_tags et WHERE et.tag_id = t.id) uses
                     FROM tag_aliases a JOIN tags t ON t.id = a.tag_id');
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
      $stems = array_map('stem_it', preg_split('/[\s_\-]+/u', (string)$x['alias'], -1, PREG_SPLIT_NO_EMPTY) ?: []);
      if (!$stems) continue;
      $x['stems'] = $stems;
      unset($x['alias']);
      $idx[$stems[0]][] = $x;
    }
  } catch (Throwable $e) { /* tabella non ancora migrata */ }
  return $idx;
}

/** Sostituisce i nomi che sono alias (tag fusi) con il tag tenuto. */
function nlp_tag_resolve_aliases(SQLite3 $db, array $names): array {
  if (!$names) return $names;
  $map = [];
  try {
    $r = $db->query('SELECT a.alias, t.name FROM tag_aliases a JOIN tags t ON t.id = a.tag_id');
    while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $map[(string)$x[0]] = (string)$x[1];
  } catch (Throwable $e) {
    return $names;
  }
  return array_values(array_unique(array_map(static fn($n) => $map[$n] ?? $n, $names)));
}

/**
 * Auto-tag di una voce. Per ogni keyword, in ordine di peso:
 *  1. se la sua radice corrisponde a un tag GIA' usato altrove, si usa quel
 *     tag (preferendo i manuali e i piu' usati): il vocabolario converge;
 *  2. altrimenti diventa un tag nuovo solo se ripetuta (>= 2 volte) e
 *     distintiva (presente in poche voci), e al massimo $max di questi.
 * Si salta cio' che e' gia' coperto da un tag manuale della voce.
 * Ritorna [nome_tag => peso].
 */
function nlp_auto_tags(SQLite3 $db, int $id, array $kws, array $corpus, array $manual_tags, int $max = 3): array {
  $manual_stems = [];
  foreach ($manual_tags as $t) {
    foreach (preg_split('/[\s_\-]+/u', (string)$t, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) $manual_stems[stem_it($w)] = true;
  }
  $idx = nlp_tag_index($db, $id);
  $have = $corpus['tf'][$id] ?? [];
  $rare = max(2, (int)ceil(0.3 * $corpus['N']));
  $out = [];
  $new = 0;
  foreach ($kws as $k) {
    $s = $k['stem'];
    if (isset($manual_stems[$s])) continue;
    $hit = null;
    foreach ($idx[$s] ?? [] as $cand) {
      foreach ($cand['stems'] as $cs) if (!isset($have[$cs])) continue 2;
      if ($hit === null
          || [$cand['kind'] === 'manual', (int)$cand['uses']] > [$hit['kind'] === 'manual', (int)$hit['uses']]) {
        $hit = $cand;
      }
    }
    if ($hit !== null) {
      if (!in_array($hit['name'], $manual_tags, true)) $out[(string)$hit['name']] = (float)$k['score'];
      continue;
    }
    if ($new < $max && $k['freq'] >= 2 && ($corpus['df'][$s] ?? 1) <= $rare) {
      $n = nlp_tag_normalize($k['term']);
      if ($n !== '' && !isset($out[$n])) { $out[$n] = (float)$k['score']; $new++; }
    }
  }
  return $out;
}

/* ============================  Lingua  ============================ */

/** Euristica leggera it/en su parole funzionali; '' se indeterminata. */
function nlp_detect_lang(string $text): string {
  static $it = ['di','che','non','per','con','una','sono','anche','come','più',
                'della','nella','sulla','questo','questa','quando','perché',
                'ancora','tutto','essere','fatto','molto','senza','dopo','loro'];
  static $en = ['the','and','that','for','with','this','from','have','not','are',
                'was','you','but','his','they','she','which','their','would',
                'there','been','about','into','than','then','them','were'];
  $hit_it = array_fill_keys($it, true);
  $hit_en = array_fill_keys($en, true);

  $words = preg_split('/\P{L}+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
  $words = array_slice($words, 0, 400);
  $ci = $ce = 0;
  foreach ($words as $w) {
    if (isset($hit_it[$w])) $ci++;
    if (isset($hit_en[$w])) $ce++;
  }
  if ($ci === 0 && $ce === 0) return '';
  return $ci >= $ce ? 'it' : 'en';
}

/* ============================  Tag  ============================ */

/** Normalizza un tag (come annotations.php di RSSIntel). '' se non valido. */
function nlp_tag_normalize(string $t): string {
  $t = trim(mb_strtolower($t, 'UTF-8'));
  $t = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $t) ?? '';
  $t = trim(preg_replace('/\s+/', ' ', $t) ?? '');
  return ($t !== '' && mb_strlen($t, 'UTF-8') <= 40) ? $t : '';
}

/**
 * Fonde una lista di tag manuali nel testo grezzo come `#hashtag` su una riga
 * finale, saltando quelli gia' presenti. Cosi' i tag aggiunti fuori dal corpo
 * (form web, comando del bot) sopravvivono a un successivo entry_update(), che
 * ricostruisce i tag solo dal `raw`.
 */
function nlp_merge_tags_into_raw(string $raw, array $tags): string {
  $have = [];
  if (preg_match_all('/(?<![\w#])#([\p{L}\p{N}][\p{L}\p{N}_\-]{1,39})/u', $raw, $m)) {
    foreach ($m[1] as $t) {
      $n = nlp_tag_normalize($t);
      if ($n !== '') $have[$n] = true;
    }
  }
  $add = [];
  foreach ($tags as $t) {
    $n = nlp_tag_normalize((string)$t);
    if ($n === '' || isset($have[$n])) continue;
    $tok = preg_replace('/[^\p{L}\p{N}_\-]/u', '', preg_replace('/\s+/', '_', $n)) ?? '';
    if ($tok !== '') { $add[] = '#' . $tok; $have[$n] = true; }
  }
  return $add ? rtrim($raw) . "\n\n" . implode(' ', $add) : $raw;
}

/** Rimuove i token `#tag` corrispondenti (nome normalizzato) dal testo grezzo. */
function nlp_strip_tag_from_raw(string $raw, string $name): string {
  $target = nlp_tag_normalize($name);
  if ($target === '') return $raw;
  $out = preg_replace_callback(
    '/(?<![\w#])#([\p{L}\p{N}][\p{L}\p{N}_\-]{1,39})/u',
    static function ($m) use ($target) {
      $n = nlp_tag_normalize(str_replace('_', ' ', $m[1]));
      $n2 = nlp_tag_normalize($m[1]);
      return ($n === $target || $n2 === $target) ? '' : $m[0];
    },
    $raw
  ) ?? $raw;
  // ricompatta spazi/righe rimaste vuote
  $out = preg_replace('/[ \t]{2,}/', ' ', $out) ?? $out;
  $out = preg_replace('/\n{3,}/', "\n\n", $out) ?? $out;
  return rtrim($out);
}

/** Ritorna l'id del tag, creandolo se assente. */
function nlp_tag_id(SQLite3 $db, string $name, string $kind = 'manual'): int {
  $st = $db->prepare('INSERT OR IGNORE INTO tags(name, kind) VALUES(:n, :k)');
  $st->bindValue(':n', $name, SQLITE3_TEXT);
  $st->bindValue(':k', $kind, SQLITE3_TEXT);
  $st->execute();
  $st = $db->prepare('SELECT id FROM tags WHERE name = :n');
  $st->bindValue(':n', $name, SQLITE3_TEXT);
  return (int)($st->execute()->fetchArray(SQLITE3_ASSOC)['id'] ?? 0);
}

/* ============================  Direttive  ============================ */

/**
 * Estrae direttive e metadati dal testo grezzo.
 * Ritorna: body, title(?), tags[], created_at(?, UTC), nolink(bool),
 *          pinned(bool), mentions[] (id o slug dai [[..]]).
 *
 * Direttive riconosciute (una per riga, la riga viene rimossa dal corpo):
 *   !data:GG/MM/AAAA            retrodata la voce (mezzogiorno, ora di Roma)
 *   !data:GG/MM/AAAA HH:MM      retrodata con ora locale (accetta anche AAAA-MM-GG)
 *   !nolink                     salta il calcolo delle correlazioni automatiche
 *   !pin                        fissa la voce
 *   !tag:a, b, c                aggiunge tag manuali
 * Inoltre, ovunque nel corpo (testo lasciato intatto):
 *   #parola                     -> tag manuale
 *   [[123]] / [[2026-09-03-7]]  -> backlink verso un'altra voce
 * Titolo (in ordine di precedenza): prima riga nella forma "Titolo :: corpo"
 * (comodo su mobile), oppure "# Titolo", oppure prima riga corta (<= 80)
 * seguita da una riga vuota. La parte-titolo viene rimossa dal corpo.
 */
function nlp_parse_directives(string $raw): array {
  $out = [
    'body' => '', 'title' => null, 'tags' => [], 'created_at' => null,
    'nolink' => false, 'pinned' => false, 'mentions' => [],
  ];
  $tags = [];

  $keep = [];
  foreach (preg_split('/\R/u', $raw) as $ln) {
    $t = trim($ln);
    if ($t !== '' && $t[0] === '!') {
      if (preg_match('~^!data:\s*(?:(\d{4})-(\d{2})-(\d{2})|(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4}))(?:[ T](\d{2}:\d{2}))?\s*$~', $t, $m)) {
        $ymd = $m[1] !== ''
          ? sprintf('%s-%s-%s', $m[1], $m[2], $m[3])
          : sprintf('%04d-%02d-%02d', (int)$m[6], (int)$m[5], (int)$m[4]);
        $local = $ymd . ' ' . (($m[7] ?? '') !== '' ? $m[7] : '12:00') . ':00';
        try {
          $dt = new DateTime($local, tzobj());
          $dt->setTimezone(new DateTimeZone('UTC'));
          $out['created_at'] = $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) { /* riga ignorata */ }
        continue;
      }
      if (preg_match('/^!nolink\s*$/i', $t)) { $out['nolink'] = true;  continue; }
      if (preg_match('/^!pin\s*$/i', $t))    { $out['pinned'] = true;  continue; }
      if (preg_match('/^!tags?:\s*(.+)$/i', $t, $m)) {
        foreach (preg_split('/,/', $m[1]) as $tg) {
          $n = nlp_tag_normalize($tg);
          if ($n !== '') $tags[$n] = true;
        }
        continue;
      }
      // '!' non riconosciuto: la riga resta nel corpo
    }
    $keep[] = $ln;
  }
  $body = trim(implode("\n", $keep));

  // #hashtag -> tag (testo lasciato intatto)
  if (preg_match_all('/(?<![\w#])#([\p{L}\p{N}][\p{L}\p{N}_\-]{1,39})/u', $body, $mm)) {
    foreach ($mm[1] as $tg) {
      $n = nlp_tag_normalize($tg);
      if ($n !== '') $tags[$n] = true;
    }
  }

  // [[id]] / [[slug]] / [[GG/MM/AAAA-N]] -> mention
  if (preg_match_all('~\[\[\s*(\d{1,9}|\d{4}-\d{2}-\d{2}-\d+|\d{1,2}/\d{1,2}/\d{4}-\d+)\s*\]\]~u', $body, $mm)) {
    $out['mentions'] = array_values(array_unique($mm[1]));
  }

  // Titolo
  $bl = preg_split('/\R/u', $body) ?: [];
  if ($bl) {
    // 1) separatore esplicito sulla prima riga:  Titolo :: corpo...
    //    (comodo su mobile: niente riga vuota). Gli spazi attorno a "::"
    //    evitano di spezzare gli URL (https://...).
    if (preg_match('/^(.{1,80}?)\s+::\s+(\S.*)$/u', $bl[0], $m)) {
      $out['title'] = trim($m[1]);
      $bl[0] = trim($m[2]);
      $body = ltrim(implode("\n", $bl));
    } elseif (preg_match('/^#\s+(.{1,120})$/', trim($bl[0]), $m)) {
      $out['title'] = trim($m[1]);
      array_shift($bl);
      $body = ltrim(implode("\n", $bl));
    } elseif (count($bl) >= 2 && trim($bl[0]) !== '' && trim($bl[1]) === ''
              && mb_strlen(trim($bl[0]), 'UTF-8') <= 80) {
      $out['title'] = trim($bl[0]);
      array_shift($bl);
      $body = ltrim(implode("\n", $bl));
    }
  }

  $out['body'] = $body;
  $out['tags'] = array_keys($tags);
  return $out;
}

/* ============================  Persistenza  ============================ */

/**
 * id, slug (AAAA-MM-GG-N) o slug in formato italiano (GG/MM/AAAA-N)
 * -> id di voce esistente, oppure null.
 */
function entry_resolve_ref(SQLite3 $db, string $ref): ?int {
  $ref = trim($ref);
  if ($ref === '') return null;
  if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})-(\d+)$~', $ref, $m)) {
    $ref = sprintf('%04d-%02d-%02d-%d', (int)$m[3], (int)$m[2], (int)$m[1], (int)$m[4]);
  }
  if (ctype_digit($ref)) {
    $st = $db->prepare('SELECT id FROM entries WHERE id = :i');
    $st->bindValue(':i', (int)$ref, SQLITE3_INTEGER);
  } else {
    $st = $db->prepare('SELECT id FROM entries WHERE slug = :s');
    $st->bindValue(':s', $ref, SQLITE3_TEXT);
  }
  $r = $st->execute()->fetchArray(SQLITE3_ASSOC);
  return $r ? (int)$r['id'] : null;
}

/**
 * Ricostruisce l'indicizzazione di una voce: termini, keyword, tag, persone,
 * vettore semantico e, se $do_links, archi in uscita e temi. Idempotente.
 * $manual_tags: nomi gia' normalizzati. $mentions: id/slug dai [[..]].
 * $opts['rebuild']: chiamata da graph_rebuild(), che ha gia' scritto i
 * termini di tutte le voci e calcola archi e temi alla fine, una volta sola.
 */
function entry_reindex(SQLite3 $db, int $id, string $body,
                       array $manual_tags, array $mentions, bool $do_links, array $opts = []): void {
  $C = cfg();
  $rebuild = !empty($opts['rebuild']);
  $title = (string)($db->querySingle('SELECT title FROM entries WHERE id = ' . $id) ?? '');
  $persons = nlp_persons($db);
  $manual_tags = array_values(array_unique(array_filter(array_map(
    static fn($t) => nlp_tag_normalize((string)$t), $manual_tags))));

  /* --- termini e modello TF-IDF --- */
  if (!$rebuild) {
    nlp_store_terms($db, $id, nlp_terms($title . "\n" . $body, nlp_person_skipwords($persons)));
  }
  $corpus = nlp_corpus($db, !$rebuild);

  /* --- keyword --- */
  $db->exec('DELETE FROM entry_keywords WHERE entry_id = ' . $id);
  $kws = nlp_keywords_for($id, $corpus, $title, (int)($C['keywords_per_entry'] ?? 8));
  $ins = $db->prepare('INSERT INTO entry_keywords(entry_id, term, freq, rank, stem, score)
                       VALUES(:e, :t, :f, :r, :s, :sc)');
  foreach ($kws as $rank => $k) {
    $ins->reset();
    $ins->bindValue(':e', $id, SQLITE3_INTEGER);
    $ins->bindValue(':t', $k['term'], SQLITE3_TEXT);
    $ins->bindValue(':f', $k['freq'], SQLITE3_INTEGER);
    $ins->bindValue(':r', $rank + 1, SQLITE3_INTEGER);
    $ins->bindValue(':s', $k['stem'], SQLITE3_TEXT);
    $ins->bindValue(':sc', $k['score'], SQLITE3_FLOAT);
    $ins->execute();
  }

  /* --- tag: manuali dal testo + auto-tag convergenti --- */
  $db->exec('DELETE FROM entry_tags WHERE entry_id = ' . $id);
  $link_et = $db->prepare(
    'INSERT INTO entry_tags(entry_id, tag_id, auto, weight) VALUES(:e,:t,:a,:w)
     ON CONFLICT(entry_id, tag_id) DO UPDATE SET auto = MIN(entry_tags.auto, excluded.auto)'
  );
  $put = static function (string $name, int $auto, float $w, string $kind) use ($db, $id, $link_et): void {
    $tid = nlp_tag_id($db, $name, $kind);
    if ($tid <= 0) return;
    $link_et->reset();
    $link_et->bindValue(':e', $id, SQLITE3_INTEGER);
    $link_et->bindValue(':t', $tid, SQLITE3_INTEGER);
    $link_et->bindValue(':a', $auto, SQLITE3_INTEGER);
    $link_et->bindValue(':w', $w, SQLITE3_FLOAT);
    $link_et->execute();
  };
  $manual_tags = nlp_tag_resolve_aliases($db, $manual_tags);
  foreach ($manual_tags as $name) $put($name, 0, 1.0, 'manual');
  // 'autotags_new_per_entry' (dal 08/10/2026): quanti tag NUOVI puo' creare
  // una voce; quelli gia' esistenti (convergenza) non hanno tetto. La vecchia
  // 'autotags_per_entry' aveva un altro significato e viene ignorata.
  $auto = nlp_auto_tags($db, $id, $kws, $corpus, $manual_tags, (int)($C['autotags_new_per_entry'] ?? 3));
  foreach ($auto as $name => $w) $put((string)$name, 1, (float)$w, 'auto');

  /* --- persone --- */
  $db->exec('DELETE FROM entry_persons WHERE entry_id = ' . $id);
  $st = $db->prepare('INSERT INTO entry_persons(entry_id, person_id, mentions) VALUES(:e, :p, :m)');
  foreach (nlp_person_hits($title . "\n" . $body, $persons, $manual_tags) as $pid => $n) {
    $st->reset();
    $st->bindValue(':e', $id, SQLITE3_INTEGER);
    $st->bindValue(':p', $pid, SQLITE3_INTEGER);
    $st->bindValue(':m', $n, SQLITE3_INTEGER);
    $st->execute();
  }

  /* --- URL citati: anteprime da scaricare (su richiesta, non qui) --- */
  links_register($db, $id, $body);

  /* --- vettore semantico (se il servizio ML e' attivo) --- */
  if (!$rebuild && ml_enabled()) entry_embed($db, $id, $title, $body);

  /* --- archi in uscita: backlink espliciti --- */
  $db->exec('DELETE FROM links WHERE src_id = ' . $id);
  $manual_dst = [];
  foreach ($mentions as $ref) {
    $dst = entry_resolve_ref($db, (string)$ref);
    if ($dst === null || $dst === $id) continue;
    $manual_dst[$dst] = true;
    $st = $db->prepare("INSERT OR IGNORE INTO links(src_id, dst_id, kind, score) VALUES(:s, :d, 'manual', 1)");
    $st->bindValue(':s', $id, SQLITE3_INTEGER);
    $st->bindValue(':d', $dst, SQLITE3_INTEGER);
    $st->execute();
  }

  if ($rebuild) return;
  if ($do_links) nlp_links_for($db, $id, $corpus, nlp_graph_ctx($db, true), $manual_dst);
  graph_temporal($db);
  graph_clusters($db, $corpus);
}

/**
 * Crea una nuova voce ed esegue l'indicizzazione.
 * $in: raw (obbl.), author (obbl.), source, title, tags[], created_at,
 *      tg_chat_id, tg_message_id, tg_from_id, pinned.
 * Ritorna ['id' => int, 'slug' => string].
 */
function entry_save(SQLite3 $db, array $in): array {
  $raw = (string)($in['raw'] ?? '');
  if (trim($raw) === '') throw new RuntimeException('Corpo vuoto.');
  $author = (string)($in['author'] ?? '');
  if ($author === '') throw new RuntimeException('Autore mancante.');

  // I tag passati a parte (form web, bot) vengono fusi nel raw come #hashtag,
  // cosi' restano la sola fonte dei tag manuali e sopravvivono a entry_update().
  $extra = array_filter(array_map('strval', (array)($in['tags'] ?? [])));
  if ($extra) $raw = nlp_merge_tags_into_raw($raw, $extra);

  $d = nlp_parse_directives($raw);
  $body  = $d['body'] !== '' ? $d['body'] : trim($raw);
  $title = $d['title'];
  if ($title === null) {
    $t = trim((string)($in['title'] ?? ''));
    $title = $t !== '' ? $t : null;
  }
  $created = $d['created_at']
    ?? (trim((string)($in['created_at'] ?? '')) ?: now_utc());
  $pinned = !empty($in['pinned']) || $d['pinned'] ? 1 : 0;

  $manual_tags = $d['tags'];

  $st = $db->prepare("
    INSERT INTO entries
      (title, body, raw, lang, source, author, created_at,
       word_count, char_count, pinned, tg_chat_id, tg_message_id, tg_from_id)
    VALUES
      (:t, :b, :r, :l, :s, :a, :c, :wc, :cc, :pin, :tc, :tm, :tf)
  ");
  $st->bindValue(':t', $title, $title === null ? SQLITE3_NULL : SQLITE3_TEXT);
  $st->bindValue(':b', $body, SQLITE3_TEXT);
  $st->bindValue(':r', $raw, SQLITE3_TEXT);
  $st->bindValue(':l', nlp_detect_lang($body) ?: null, SQLITE3_TEXT);
  $st->bindValue(':s', (string)($in['source'] ?? 'web'), SQLITE3_TEXT);
  $st->bindValue(':a', $author, SQLITE3_TEXT);
  $st->bindValue(':c', $created, SQLITE3_TEXT);
  $st->bindValue(':wc', word_count($body), SQLITE3_INTEGER);
  $st->bindValue(':cc', mb_strlen($body, 'UTF-8'), SQLITE3_INTEGER);
  $st->bindValue(':pin', $pinned, SQLITE3_INTEGER);
  $st->bindValue(':tc', isset($in['tg_chat_id']) ? (int)$in['tg_chat_id'] : null,
                 isset($in['tg_chat_id']) ? SQLITE3_INTEGER : SQLITE3_NULL);
  $st->bindValue(':tm', isset($in['tg_message_id']) ? (int)$in['tg_message_id'] : null,
                 isset($in['tg_message_id']) ? SQLITE3_INTEGER : SQLITE3_NULL);
  $st->bindValue(':tf', isset($in['tg_from_id']) ? (int)$in['tg_from_id'] : null,
                 isset($in['tg_from_id']) ? SQLITE3_INTEGER : SQLITE3_NULL);
  $st->execute();

  $id = (int)$db->lastInsertRowID();
  $slug = local_ymd($created) . '-' . $id;
  $u = $db->prepare('UPDATE entries SET slug = :s WHERE id = :i');
  $u->bindValue(':s', $slug, SQLITE3_TEXT);
  $u->bindValue(':i', $id, SQLITE3_INTEGER);
  $u->execute();

  entry_reindex($db, $id, $body, $manual_tags, $d['mentions'], !$d['nolink']);

  return ['id' => $id, 'slug' => $slug] + entry_hints($db, $id, (string)$title, $body);
}

/**
 * Dopo un salvataggio: nomi propri ancora sconosciuti (da confermare come
 * persone) e tag gia' usati altrove che si adattano alla voce.
 */
function entry_hints(SQLite3 $db, int $id, string $title, string $body): array {
  return [
    'candidates' => nlp_person_candidates($db, $title . "\n" . $body, nlp_persons($db), 3, true),
    'suggest'    => nlp_suggest_tags($db, $id),
  ];
}

/** Testo segnaposto delle voci nate da un allegato senza didascalia. */
const ENTRY_PLACEHOLDER = '(allegato senza testo)';

/**
 * Aggiunge testo in coda a una voce (risposta a una scheda nel bot, seguito
 * di un pensiero), con una riga che data l'aggiunta. Se la voce era solo un
 * segnaposto d'allegato, il testo lo sostituisce.
 */
function entry_append(SQLite3 $db, int $id, string $text, string $editor): array {
  $text = trim($text);
  if ($text === '') throw new RuntimeException('Testo vuoto.');
  $raw = (string)$db->querySingle('SELECT raw FROM entries WHERE id = ' . $id);
  if (trim($raw) === ENTRY_PLACEHOLDER || trim($raw) === '') {
    $new = $text;
  } else {
    $stamp = (new DateTime('now', tzobj()))->format('d/m/Y H:i');
    $new = rtrim($raw) . "\n\n_(aggiunto il $stamp)_\n" . $text;
  }
  return entry_update($db, $id, $new, $editor);
}

/**
 * Registra la trascrizione di un vocale e la porta nel testo della voce:
 * se la voce non aveva testo, la trascrizione diventa il testo; altrimenti
 * viene accodata (cosi' e' cercabile, taggata e correlata come il resto).
 */
function attachment_transcript(SQLite3 $db, int $att_id, string $text, string $editor): array {
  $a = $db->querySingle('SELECT id, entry_id FROM attachments WHERE id = ' . $att_id, true);
  if (!$a) throw new RuntimeException('Allegato inesistente.');
  $text = trim($text);
  $st = $db->prepare("UPDATE attachments SET transcript = :t, transcript_status = :s WHERE id = :i");
  $st->bindValue(':t', $text !== '' ? $text : null, $text !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
  $st->bindValue(':s', $text !== '' ? 'done' : 'error', SQLITE3_TEXT);
  $st->bindValue(':i', $att_id, SQLITE3_INTEGER);
  $st->execute();
  $eid = (int)$a['entry_id'];
  if ($text === '') return ['id' => $eid, 'slug' => (string)$db->querySingle('SELECT slug FROM entries WHERE id=' . $eid)];
  $raw = (string)$db->querySingle('SELECT raw FROM entries WHERE id = ' . $eid);
  $new = (trim($raw) === ENTRY_PLACEHOLDER || trim($raw) === '') ? $text : rtrim($raw) . "\n\n🎙 " . $text;
  return entry_update($db, $eid, $new, $editor);
}

/**
 * Riprocessa una voce esistente dal suo testo grezzo aggiornato.
 * Lo slug (permalink) NON cambia. Ritorna ['id','slug'].
 */
function entry_update(SQLite3 $db, int $id, string $raw, string $editor): array {
  $cur = $db->querySingle('SELECT slug, created_at, pinned FROM entries WHERE id = ' . $id, true);
  if (!$cur) throw new RuntimeException('Voce inesistente.');
  if (trim($raw) === '') throw new RuntimeException('Corpo vuoto.');

  $d = nlp_parse_directives($raw);
  $body  = $d['body'] !== '' ? $d['body'] : trim($raw);
  $title = $d['title'];
  $created = $d['created_at'] ?? (string)$cur['created_at'];

  $st = $db->prepare("
    UPDATE entries SET
      title = :t, body = :b, raw = :r, lang = :l, created_at = :c,
      word_count = :wc, char_count = :cc, pinned = :pin,
      updated_at = :now
    WHERE id = :i
  ");
  $st->bindValue(':t', $title, $title === null ? SQLITE3_NULL : SQLITE3_TEXT);
  $st->bindValue(':b', $body, SQLITE3_TEXT);
  $st->bindValue(':r', $raw, SQLITE3_TEXT);
  $st->bindValue(':l', nlp_detect_lang($body) ?: null, SQLITE3_TEXT);
  $st->bindValue(':c', $created, SQLITE3_TEXT);
  $st->bindValue(':wc', word_count($body), SQLITE3_INTEGER);
  $st->bindValue(':cc', mb_strlen($body, 'UTF-8'), SQLITE3_INTEGER);
  // Il pin e' uno stato della voce, non del testo: una modifica non lo azzera.
  // La direttiva !pin puo' solo ATTIVARLO; per toglierlo si usa il pulsante.
  $st->bindValue(':pin', ($d['pinned'] || (int)$cur['pinned'] === 1) ? 1 : 0, SQLITE3_INTEGER);
  $st->bindValue(':now', now_utc(), SQLITE3_TEXT);
  $st->bindValue(':i', $id, SQLITE3_INTEGER);
  $st->execute();

  entry_reindex($db, $id, $body, $d['tags'], $d['mentions'], !$d['nolink']);

  return ['id' => $id, 'slug' => (string)$cur['slug']] + entry_hints($db, $id, (string)$title, $body);
}

/**
 * Ricostruisce integralmente termini, keyword, tag, persone, archi e temi di
 * TUTTE le voci (e completa i vettori semantici mancanti, se il servizio ML
 * risponde). Da lanciare dopo import massivi, dopo aver confermato persone o
 * fuso tag, e ogni notte (timer): rende il grafo simmetrico e ricalcola i
 * pesi TF-IDF sul diario aggiornato.
 * Ritorna ['entries', 'edges', 'clusters', 'vectors', 'seconds'].
 */
function graph_rebuild(SQLite3 $db): array {
  $t0 = microtime(true);
  $rows = [];
  $r = $db->query('SELECT id, title, body, raw FROM entries ORDER BY id');
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
    $src = ((string)$x['raw'] !== '') ? (string)$x['raw'] : (string)$x['body'];
    $d = nlp_parse_directives($src);
    $x['d'] = $d;
    $x['clean'] = $d['body'] !== '' ? $d['body'] : (string)$x['body'];
    $rows[] = $x;
  }

  // vettori semantici: fuori dalla transazione (chiamate HTTP al servizio ML)
  $vectors = ml_enabled() ? ml_embed_missing($db, 5000) : -1;

  $db->exec('BEGIN IMMEDIATE');
  try {
    $persons = nlp_persons($db, true);
    $skip = nlp_person_skipwords($persons);

    // 1) termini di tutte le voci, poi il modello TF-IDF una volta sola
    foreach ($rows as $x) {
      nlp_store_terms($db, (int)$x['id'], nlp_terms((string)$x['title'] . "\n" . $x['clean'], $skip));
    }
    $corpus = nlp_corpus($db, true);

    // 2) keyword, tag, persone, backlink espliciti (gli auto-tag ripartono
    //    da zero, cosi' la convergenza sul vocabolario dipende solo dai dati)
    $db->exec('DELETE FROM links');
    $db->exec('DELETE FROM entry_tags WHERE auto = 1');
    foreach ($rows as $x) {
      entry_reindex($db, (int)$x['id'], $x['clean'], $x['d']['tags'], $x['d']['mentions'],
                    !$x['d']['nolink'], ['rebuild' => true]);
    }

    // 3) correlazioni per similarita', in un solo passaggio sul contesto finale
    $ctx = nlp_graph_ctx($db, true);
    foreach ($rows as $x) {
      if ($x['d']['nolink']) continue;
      $manual = [];
      foreach ($x['d']['mentions'] as $ref) {
        $dst = entry_resolve_ref($db, (string)$ref);
        if ($dst !== null) $manual[$dst] = true;
      }
      nlp_links_for($db, (int)$x['id'], $corpus, $ctx, $manual);
    }
    graph_temporal($db);
    $nclusters = graph_clusters($db, $corpus);

    // tag automatici rimasti senza voci: via
    $db->exec("DELETE FROM tags WHERE kind = 'auto' AND id NOT IN (SELECT tag_id FROM entry_tags)");
    $db->exec('COMMIT');
  } catch (Throwable $e) {
    $db->exec('ROLLBACK');
    throw $e;
  }

  return [
    'entries'  => count($rows),
    'edges'    => (int)$db->querySingle("SELECT COUNT(*) FROM links WHERE kind <> 'temporal'"),
    'clusters' => $nclusters,
    'vectors'  => $vectors,
    'seconds'  => round(microtime(true) - $t0, 2),
  ];
}

/**
 * Elimina una voce E i file dei suoi allegati dal disco.
 * Va usata al posto di un semplice DELETE: il vincolo ON DELETE CASCADE
 * rimuove le righe di `attachments` ma lascerebbe i file orfani per sempre
 * (audit 14/09/2026). I percorsi vanno letti PRIMA della cancellazione.
 * Ritorna ['label' => string, 'files' => int].
 */
function entry_delete(SQLite3 $db, int $id): array {
  $row = $db->querySingle('SELECT id, title, body FROM entries WHERE id = ' . $id, true);
  if (!$row) throw new RuntimeException('Voce inesistente.');
  $label = trim((string)($row['title'] ?? ''));
  if ($label === '') $label = first_line((string)($row['body'] ?? ''), 70);
  if ($label === '') $label = 'voce ' . $id;

  $paths = [];
  $st = $db->prepare('SELECT path FROM attachments WHERE entry_id = :i');
  $st->bindValue(':i', $id, SQLITE3_INTEGER);
  $r = $st->execute();
  while ($x = $r->fetchArray(SQLITE3_ASSOC)) $paths[] = (string)$x['path'];

  $db->exec('DELETE FROM entries WHERE id = ' . $id);

  $removed = 0;
  $base = realpath((string)cfg()['attachments_dir']);
  if ($base !== false) {
    foreach ($paths as $rel) {
      $full = realpath($base . '/' . $rel);
      // stesso prefix-check di attachment.php: mai uscire da attachments_dir
      if ($full !== false && str_starts_with($full, $base . DIRECTORY_SEPARATOR) && is_file($full)) {
        if (@unlink($full)) $removed++;
      }
    }
    @rmdir($base . '/' . $id);   // rimuove la cartella della voce se vuota
  }
  return ['label' => $label, 'files' => $removed];
}

/* ============================  Persone  ============================ */

/**
 * Parole che iniziano con la maiuscola ma quasi mai sono persone: mesi,
 * giorni, luoghi comuni, istituzioni, festivita', marchi. Servono solo a non
 * proporre candidati inutili; la decisione finale resta all'utente.
 */
const NLP_NOT_PERSON = [
  'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre',
  'ottobre', 'novembre', 'dicembre', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì',
  'sabato', 'domenica', 'italia', 'europa', 'germania', 'francia', 'spagna', 'inghilterra',
  'regno', 'unito', 'stati', 'uniti', 'america', 'usa', 'russia', 'ucraina', 'cina', 'giappone',
  'india', 'israele', 'palestina', 'gaza', 'iran', 'iraq', 'siria', 'turchia', 'grecia', 'svizzera',
  'austria', 'polonia', 'olanda', 'belgio', 'portogallo', 'africa', 'asia', 'oriente', 'occidente',
  'medio', 'nord', 'sud', 'est', 'ovest', 'mediterraneo', 'roma', 'milano', 'napoli', 'torino',
  'firenze', 'bologna', 'venezia', 'genova', 'palermo', 'bari', 'catania', 'verona', 'siena',
  'pisa', 'parma', 'londra', 'parigi', 'berlino', 'madrid', 'mosca', 'kiev', 'washington',
  'bruxelles', 'toscana', 'lombardia', 'lazio', 'sicilia', 'sardegna', 'piemonte', 'veneto',
  'campania', 'puglia', 'calabria', 'liguria', 'emilia', 'romagna', 'umbria', 'marche',
  'abruzzo', 'sassonia', 'baviera', 'dio', 'gesù', 'cristo', 'madonna', 'chiesa', 'papa',
  'stato', 'governo', 'parlamento', 'senato', 'camera', 'repubblica', 'costituzione', 'comune',
  'regione', 'provincia', 'ministero', 'natale', 'pasqua', 'capodanno', 'ferragosto',
  'internet', 'telegram', 'google', 'facebook', 'whatsapp', 'youtube', 'twitter', 'instagram',
  'amazon', 'apple', 'microsoft', 'linux', 'windows', 'android', 'iphone', 'claude', 'github',
  'nato', 'onu', 'unione', 'europea', 'occidentale', 'orientale',
];

/**
 * Persone confermate: [id => ['id', 'name', 'names' => nome + alias]].
 * Memoizzato; $refresh dopo aver aggiunto/modificato persone.
 */
function nlp_persons(SQLite3 $db, bool $refresh = false): array {
  static $p = null;
  if ($p !== null && !$refresh) return $p;
  $p = [];
  try {
    $r = $db->query('SELECT id, name, aliases FROM persons ORDER BY id');
  } catch (Throwable $e) {
    return $p;   // tabella non ancora migrata
  }
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
    $names = [trim((string)$x['name'])];
    foreach (explode(',', (string)$x['aliases']) as $a) {
      $a = trim($a);
      if ($a !== '') $names[] = $a;
    }
    usort($names, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));   // prima i piu' lunghi
    $p[(int)$x['id']] = ['id' => (int)$x['id'], 'name' => (string)$x['name'], 'names' => $names];
  }
  return $p;
}

/** Parole (minuscole) dei nomi e alias delle persone note. */
function nlp_person_skipwords(array $persons): array {
  $out = [];
  foreach ($persons as $p) {
    foreach ($p['names'] as $n) {
      foreach (nlp_words($n) as $w) $out[$w] = true;
    }
  }
  return $out;
}

/**
 * Menzioni di persone note nel testo: [person_id => occorrenze]. Confronto
 * per parola intera, senza distinguere maiuscole; conta anche il nome come
 * hashtag (#jamal) e come tag manuale senza spazi (#marioscarpati).
 */
function nlp_person_hits(string $text, array $persons, array $tags = []): array {
  $hits = [];
  $tagset = array_fill_keys($tags, true);
  foreach ($persons as $id => $p) {
    $t = $text;
    $n = 0;
    foreach ($p['names'] as $nm) {
      $re = '/(?<![\p{L}\p{N}_])' . str_replace('\ ', '\s+', preg_quote($nm, '/')) . '(?![\p{L}\p{N}_])/iu';
      $c = 0;
      $t = preg_replace($re, ' ', $t, -1, $c) ?? $t;   // un nome lungo non riconta l'alias contenuto
      $n += $c;
    }
    if ($n === 0) {
      $flat = nlp_tag_normalize(str_replace(' ', '', $p['name']));
      if ($flat !== '' && isset($tagset[$flat])) $n = 1;
    }
    if ($n > 0) $hits[$id] = $n;
  }
  return $hits;
}

/**
 * Nomi propri candidati (non ancora persone, non scartati): sequenze di
 * 1-3 parole con la maiuscola NON a inizio frase ("ho visto Nathan",
 * "con Mario Scarpati"). Una sola parola maiuscola a inizio frase non conta
 * (e' solo l'inizio della frase). Ritorna i nomi, i piu' frequenti prima.
 */
function nlp_person_candidates(SQLite3 $db, string $text, array $persons, int $max = 6, bool $likely_only = false): array {
  static $notp = null;
  if ($notp === null) $notp = array_fill_keys(NLP_NOT_PERSON, true);
  $ignore = [];   // letta ogni volta: un "no" appena dato deve valere subito
  try {
    $r = $db->query('SELECT name FROM entity_ignore');
    while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $ignore[mb_strtolower((string)$x[0], 'UTF-8')] = true;
  } catch (Throwable $e) { /* tabella non ancora migrata */ }
  $known = [];
  foreach ($persons as $p) {
    foreach ($p['names'] as $n) {
      $known[mb_strtolower($n, 'UTF-8')] = true;
      foreach (nlp_words($n) as $w) $known[$w] = true;
    }
  }
  $stop = nlp_stopwords();

  $text = preg_replace('~https?://\S+~u', ' ', $text) ?? $text;
  // parole che nel testo compaiono ANCHE in minuscolo: sono nomi comuni
  // scritti con la maiuscola (titoli, enfasi), non nomi di persona
  $lower = [];
  if (preg_match_all('/(?<![\p{L}#@_])\p{Ll}[\p{Ll}]*/u', $text, $lm))   // gli #hashtag non contano $lower = array_fill_keys($lm[0], true);
  if (!preg_match_all("/\p{Lu}[\p{Ll}'’]+(?:[ \t]+\p{Lu}[\p{Ll}'’]+){0,2}/u", $text, $m, PREG_OFFSET_CAPTURE)) return [];

  $cands = [];
  foreach ($m[0] as [$name, $off]) {
    // carattere significativo precedente (salta spazi, virgolette, parentesi)
    $i = $off - 1;
    while ($i >= 0 && strpos(" \t\"'«»*_([", $text[$i]) !== false) $i--;
    if ($i >= 0 && ($text[$i] === '#' || $text[$i] === '@')) continue;            // hashtag/menzione
    if ($i >= 0 && ord($text[$i]) >= 0x80) {                                        // virgolette tipografiche
      $j = $i; while ($j > 0 && (ord($text[$j]) & 0xC0) === 0x80) $j--;
      if (in_array(substr($text, $j, $i - $j + 1), ['“', '”', '‘', '’', '«', '»'], true)) {
        $i = $j - 1;
        while ($i >= 0 && strpos(" \t", $text[$i]) !== false) $i--;
      }
    }
    $start = $i < 0 || strpos(".!?\n:;", $text[$i]) !== false;

    $words = preg_split('/\s+/u', trim($name)) ?: [];
    // via le parole "comuni" in testa e in coda (Oggi Nathan -> Nathan)
    $isnoise = static function (string $w) use ($stop, $notp): bool {
      $l = mb_strtolower($w, 'UTF-8');
      return isset($stop[$l]) || isset($notp[$l]) || mb_strlen($l, 'UTF-8') < 3
          // suffissi da nome comune/astratto, mai da persona
          || preg_match('/(ismo|ismi|ista|isti|iste|zione|zioni|mente|ità|logia|logie)$/u', $l);
    };
    $lead = 0;
    while ($words && $isnoise($words[0])) { array_shift($words); $lead++; }
    while ($words && $isnoise($words[count($words) - 1])) array_pop($words);
    if (!$words) continue;
    if ($start && $lead === 0 && count($words) === 1) continue;   // solo maiuscola di inizio frase

    $inlower = 0;
    foreach ($words as $w) if (isset($lower[mb_strtolower($w, 'UTF-8')])) $inlower++;
    if ($inlower * 2 >= count($words) + (count($words) > 1 ? 1 : 0)) continue;

    $nm = implode(' ', $words);
    $low = mb_strtolower($nm, 'UTF-8');
    if (isset($known[$low]) || isset($ignore[$low])) continue;
    // nome e cognome valgono piu' di una parola sola
    $cands[$nm] = ($cands[$nm] ?? 0) + (count($words) > 1 ? 2 : 1);
  }
  // prima le persone probabili (contengono un nome di battesimo)
  $rank = [];
  foreach ($cands as $nm => $c) $rank[$nm] = [nlp_is_likely_person((string)$nm), $c];
  uksort($rank, static fn($a, $b) => [$rank[$b], $a] <=> [$rank[$a], $b]);
  $out = array_keys($rank);
  if ($likely_only) $out = array_values(array_filter($out, static fn($n) => $rank[$n][0]));
  return array_slice(array_map('strval', $out), 0, $max);
}

/** Il candidato contiene un nome di battesimo comune (firstnames.php)? */
function nlp_is_likely_person(string $name): bool {
  static $fn = null;
  if ($fn === null) {
    $f = __DIR__ . '/firstnames.php';
    $fn = is_file($f) ? array_fill_keys(require $f, true) : [];
  }
  foreach (preg_split('/\s+/u', mb_strtolower($name, 'UTF-8')) ?: [] as $w) {
    if (isset($fn[$w])) return true;
  }
  return false;
}

/** Crea (o ritrova) una persona; ritorna l'id. */
function person_add(SQLite3 $db, string $name, string $aliases = ''): int {
  $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
  if ($name === '' || mb_strlen($name, 'UTF-8') > 60) throw new RuntimeException('nome non valido');
  $st = $db->prepare('INSERT OR IGNORE INTO persons(name, aliases) VALUES(:n, :a)');
  $st->bindValue(':n', $name, SQLITE3_TEXT);
  $st->bindValue(':a', trim($aliases), SQLITE3_TEXT);
  $st->execute();
  $st = $db->prepare('SELECT id FROM persons WHERE name = :n');
  $st->bindValue(':n', $name, SQLITE3_TEXT);
  $id = (int)($st->execute()->fetchArray(SQLITE3_NUM)[0] ?? 0);
  $del = $db->prepare('DELETE FROM entity_ignore WHERE name = :n');
  $del->bindValue(':n', $name, SQLITE3_TEXT);
  $del->execute();
  nlp_persons($db, true);
  return $id;
}

/** Segna un nome come "non e' una persona" (non verra' piu' proposto). */
function person_ignore(SQLite3 $db, string $name): void {
  $st = $db->prepare('INSERT OR IGNORE INTO entity_ignore(name) VALUES(:n)');
  $st->bindValue(':n', trim($name), SQLITE3_TEXT);
  $st->execute();
}

/**
 * Riconosce le menzioni di persone in TUTTE le voci (dopo aver aggiunto o
 * modificato una persona), senza ricalcolare il resto. Ritorna le voci toccate.
 */
function persons_reindex(SQLite3 $db): int {
  $persons = nlp_persons($db, true);
  $rows = [];
  $r = $db->query('SELECT id, title, body, raw FROM entries');
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $rows[] = $x;
  $db->exec('DELETE FROM entry_persons');
  $st = $db->prepare('INSERT INTO entry_persons(entry_id, person_id, mentions) VALUES(:e, :p, :m)');
  $touched = 0;
  foreach ($rows as $x) {
    $d = nlp_parse_directives((string)($x['raw'] ?: $x['body']));
    $hits = nlp_person_hits((string)$x['title'] . "\n" . (string)$x['body'], $persons, $d['tags']);
    if ($hits) $touched++;
    foreach ($hits as $pid => $n) {
      $st->reset();
      $st->bindValue(':e', (int)$x['id'], SQLITE3_INTEGER);
      $st->bindValue(':p', $pid, SQLITE3_INTEGER);
      $st->bindValue(':m', $n, SQLITE3_INTEGER);
      $st->execute();
    }
  }
  return $touched;
}

/* ============================  Correlazioni  ============================ */

/**
 * Contesto per le similarita': tag, persone e vettori di tutte le voci.
 * Memoizzato; $refresh dopo aver riscritto tag/persone/vettori.
 */
function nlp_graph_ctx(SQLite3 $db, bool $refresh = false): array {
  static $c = null;
  if ($c !== null && !$refresh) return $c;
  $tags = $pers = [];
  $r = $db->query('SELECT entry_id, tag_id FROM entry_tags');
  while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $tags[(int)$x[0]][(int)$x[1]] = true;
  $r = $db->query('SELECT entry_id, person_id FROM entry_persons');
  while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $pers[(int)$x[0]][(int)$x[1]] = true;
  return $c = ['tags' => $tags, 'persons' => $pers, 'vec' => entry_vectors_all($db)];
}

/**
 * Similarita' fra una voce e tutte le altre che hanno qualcosa in comune:
 *   [other_id => ['score' => 0..1, 'kind' => keyword|semantic|tag|person,
 *                 'parts' => [...]]], ordinata per punteggio.
 * Componenti (tutte in 0..1):
 *   t  coseno fra i vettori TF-IDF          (stesse parole, pesate)
 *   e  coseno fra gli embedding, riscalato  (stesso significato)
 *   g  Jaccard sui tag                      (stessa etichettatura)
 *   p  persone in comune (2+ = 1)
 * Pesi con embedding 0.35 t + 0.30 e + 0.20 g + 0.15 p; senza, 0.55 t +
 * 0.30 g + 0.15 p. Il tipo dell'arco e' la componente che pesa di piu'.
 */
function nlp_similarities(int $id, array $corpus, array $ctx): array {
  $C = cfg();
  $floor = (float)($C['semantic_floor'] ?? 0.83);   // e5: mediana fra voci ~0.81 (misurata 08/10/2026)

  $dot = [];
  foreach ($corpus['w'][$id] ?? [] as $s => $w) {
    foreach ($corpus['post'][$s] ?? [] as $o => $w2) {
      if ($o !== $id) $dot[$o] = ($dot[$o] ?? 0.0) + $w * $w2;
    }
  }
  $cand = $dot;
  $mt = $ctx['tags'][$id] ?? [];
  $mp = $ctx['persons'][$id] ?? [];
  $mv = $ctx['vec'][$id] ?? null;
  foreach ($ctx['tags'] as $o => $ts) if ($o !== $id && array_intersect_key($ts, $mt)) $cand[$o] = $cand[$o] ?? 0.0;
  foreach ($ctx['persons'] as $o => $ps) if ($o !== $id && array_intersect_key($ps, $mp)) $cand[$o] = $cand[$o] ?? 0.0;
  if ($mv !== null) foreach ($ctx['vec'] as $o => $v) if ($o !== $id) $cand[$o] = $cand[$o] ?? 0.0;

  $out = [];
  $n1 = $corpus['norm'][$id] ?? 0.0;
  foreach ($cand as $o => $d) {
    $n2 = $corpus['norm'][$o] ?? 0.0;
    $t = ($n1 > 0 && $n2 > 0) ? min(1.0, $d / ($n1 * $n2)) : 0.0;
    $ot = $ctx['tags'][$o] ?? [];
    $union = count($mt + $ot);
    $g = $union > 0 ? count(array_intersect_key($mt, $ot)) / $union : 0.0;
    $p = min(1.0, count(array_intersect_key($mp, $ctx['persons'][$o] ?? [])) / 2);
    $ov = $ctx['vec'][$o] ?? null;
    if ($mv !== null && $ov !== null) {
      $e = max(0.0, (vec_dot($mv, $ov) - $floor) / (1 - $floor));
      $parts = ['keyword' => 0.35 * $t, 'semantic' => 0.30 * $e, 'tag' => 0.20 * $g, 'person' => 0.15 * $p];
    } else {
      $parts = ['keyword' => 0.55 * $t, 'tag' => 0.30 * $g, 'person' => 0.15 * $p];
    }
    $score = array_sum($parts);
    if ($score <= 0) continue;
    arsort($parts);
    $out[$o] = ['score' => round($score, 4), 'kind' => (string)array_key_first($parts), 'parts' => $parts];
  }
  uasort($out, static fn($a, $b) => $b['score'] <=> $a['score']);
  return $out;
}

/** Scrive gli archi automatici in uscita di una voce. Ritorna quanti. */
function nlp_links_for(SQLite3 $db, int $id, array $corpus, array $ctx, array $skip = []): int {
  $C = cfg();
  $min = (float)($C['link_min_score'] ?? 0.08);
  $max = (int)($C['correlate_max_links'] ?? 8);
  $st = $db->prepare('INSERT OR IGNORE INTO links(src_id, dst_id, kind, score) VALUES(:s, :d, :k, :sc)');
  $n = 0;
  foreach (nlp_similarities($id, $corpus, $ctx) as $o => $x) {
    if ($n >= $max || $x['score'] < $min) break;
    if (isset($skip[$o])) continue;   // c'e' gia' il backlink esplicito
    $st->reset();
    $st->bindValue(':s', $id, SQLITE3_INTEGER);
    $st->bindValue(':d', $o, SQLITE3_INTEGER);
    $st->bindValue(':k', $x['kind'], SQLITE3_TEXT);
    $st->bindValue(':sc', $x['score'], SQLITE3_FLOAT);
    $st->execute();
    $n++;
  }
  return $n;
}

/**
 * Archi temporali: collega ogni voce alla successiva se scritte a meno di
 * 24 ore di distanza e non gia' correlate. Peso basso: servono alla mappa
 * (filo cronologico), non compaiono fra i "correlati".
 */
function graph_temporal(SQLite3 $db): void {
  $db->exec("DELETE FROM links WHERE kind = 'temporal'");
  $prev = null;
  $st = $db->prepare("INSERT OR IGNORE INTO links(src_id, dst_id, kind, score) VALUES(:s, :d, 'temporal', 0.05)");
  $r = $db->query('SELECT id, created_at FROM entries ORDER BY created_at, id');
  $rows = [];
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $rows[] = $x;
  foreach ($rows as $x) {
    if ($prev !== null && strtotime($x['created_at'] . ' UTC') - strtotime($prev['created_at'] . ' UTC') <= 86400) {
      $a = (int)$prev['id']; $b = (int)$x['id'];
      $linked = (int)$db->querySingle("SELECT COUNT(*) FROM links WHERE kind <> 'temporal'
                 AND ((src_id=$a AND dst_id=$b) OR (src_id=$b AND dst_id=$a))");
      if ($linked === 0) {
        $st->reset();
        $st->bindValue(':s', $b, SQLITE3_INTEGER);
        $st->bindValue(':d', $a, SQLITE3_INTEGER);
        $st->execute();
      }
    }
    $prev = $x;
  }
}

/**
 * Temi: gruppi di voci fortemente collegate fra loro (propagazione delle
 * etichette sul grafo pesato, deterministica). Ogni tema ha un'etichetta fatta
 * dai termini che piu' lo caratterizzano. Le voci isolate restano senza tema.
 * Ritorna il numero di temi.
 */
function graph_clusters(SQLite3 $db, ?array $corpus = null): int {
  $corpus ??= nlp_corpus($db);
  $adj = [];
  $r = $db->query("SELECT src_id, dst_id, MAX(score) s FROM links WHERE kind <> 'temporal'
                   GROUP BY src_id, dst_id");
  while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) {
    [$a, $b, $w] = [(int)$x[0], (int)$x[1], (float)$x[2]];
    if ($a === $b) continue;
    $adj[$a][$b] = max($adj[$a][$b] ?? 0, $w);
    $adj[$b][$a] = max($adj[$b][$a] ?? 0, $w);
  }
  $nodes = array_keys($adj);
  sort($nodes);
  $label = array_combine($nodes, $nodes) ?: [];
  for ($it = 0; $it < 30; $it++) {
    $changed = false;
    foreach ($nodes as $n) {
      $acc = [];
      foreach ($adj[$n] as $o => $w) $acc[$label[$o]] = ($acc[$label[$o]] ?? 0) + $w;
      $best = $label[$n]; $bw = $acc[$best] ?? -1;
      ksort($acc);
      foreach ($acc as $l => $w) if ($w > $bw + 1e-9) { $best = $l; $bw = $w; }
      if ($best !== $label[$n]) { $label[$n] = $best; $changed = true; }
    }
    if (!$changed) break;
  }
  $groups = [];
  foreach ($label as $n => $l) $groups[$l][] = $n;
  $groups = array_values(array_filter($groups, static fn($g) => count($g) >= 2));
  usort($groups, static fn($a, $b) => [count($b), min($a)] <=> [count($a), min($b)]);

  $db->exec('UPDATE entries SET cluster = NULL');
  $db->exec('DELETE FROM clusters');
  $skip = nlp_person_skipwords(nlp_persons($db));
  $ins = $db->prepare('INSERT INTO clusters(id, label, size, terms) VALUES(:i, :l, :s, :t)');
  foreach ($groups as $k => $members) {
    $cid = $k + 1;
    $sum = [];
    foreach ($members as $m) foreach ($corpus['w'][$m] ?? [] as $s => $w) $sum[$s] = ($sum[$s] ?? 0) + $w;
    // un termine presente in un solo membro non caratterizza il gruppo
    foreach ($sum as $s => $w) {
      $in = 0;
      foreach ($members as $m) if (isset($corpus['w'][$m][$s])) $in++;
      if ($in < 2 && count($members) > 2) unset($sum[$s]);
    }
    arsort($sum);
    $terms = [];
    foreach (array_keys($sum) as $s) {
      $f = nlp_display_form($corpus, (string)$s);
      if (isset($skip[$f])) continue;
      $terms[] = $f;
      if (count($terms) >= 5) break;
    }
    $ins->reset();
    $ins->bindValue(':i', $cid, SQLITE3_INTEGER);
    $ins->bindValue(':l', $terms ? implode(' · ', array_slice($terms, 0, 3)) : ('tema ' . $cid), SQLITE3_TEXT);
    $ins->bindValue(':s', count($members), SQLITE3_INTEGER);
    $ins->bindValue(':t', implode(',', $terms), SQLITE3_TEXT);
    $ins->execute();
    $db->exec('UPDATE entries SET cluster = ' . $cid . ' WHERE id IN (' . implode(',', array_map('intval', $members)) . ')');
  }
  return count($groups);
}

/** Etichetta leggibile del tipo di arco. */
function link_kind_label(string $k): string {
  return ['keyword' => 'parole', 'semantic' => 'significato', 'tag' => 'tag', 'person' => 'persone',
          'manual' => 'collegamento', 'temporal' => 'stesso periodo'][$k] ?? $k;
}

/**
 * Voci piu' vicine per significato (embedding), escluse quelle in $skip.
 * [id => coseno]. Vuoto se la voce non ha ancora un vettore.
 */
function entry_semantic_neighbors(SQLite3 $db, int $id, int $limit = 5, array $skip = []): array {
  $all = entry_vectors_all($db);
  if (!isset($all[$id])) return [];
  $floor = (float)(cfg()['semantic_floor'] ?? 0.83);
  $sims = [];
  foreach ($all as $o => $v) {
    if ($o === $id || isset($skip[$o])) continue;
    $c = vec_dot($all[$id], $v);
    if ($c >= $floor) $sims[$o] = $c;
  }
  arsort($sims);
  return array_slice($sims, 0, $limit, true);
}

/* ============================  Suggerimenti  ============================ */

/**
 * Tag gia' usati altrove che si adattano a questa voce e non le sono ancora
 * assegnati: la loro radice compare nel testo, oppure accompagnano spesso le
 * stesse persone. Servono a far convergere il vocabolario. Ritorna i nomi.
 */
function nlp_suggest_tags(SQLite3 $db, int $id, int $limit = 5): array {
  $corpus = nlp_corpus($db);
  $have = [];
  $r = $db->query('SELECT t.name FROM entry_tags et JOIN tags t ON t.id = et.tag_id WHERE et.entry_id = ' . $id);
  while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $have[(string)$x[0]] = true;
  $w = $corpus['w'][$id] ?? [];
  $score = [];
  foreach (nlp_tag_index($db, $id) as $cands) {
    foreach ($cands as $c) {
      if (isset($have[$c['name']])) continue;
      $sum = 0.0;
      foreach ($c['stems'] as $s) { if (!isset($w[$s])) continue 2; $sum += $w[$s]; }
      $score[(string)$c['name']] = $sum * log(1 + (int)$c['uses']) * ($c['kind'] === 'manual' ? 1.3 : 1.0);
    }
  }
  // tag manuali che accompagnano spesso le persone citate in questa voce
  $r = $db->query("SELECT t.name, COUNT(*) c FROM entry_persons ep
     JOIN entry_persons ep2 ON ep2.person_id = ep.person_id AND ep2.entry_id <> ep.entry_id
     JOIN entry_tags et ON et.entry_id = ep2.entry_id AND et.auto = 0
     JOIN tags t ON t.id = et.tag_id
     WHERE ep.entry_id = $id GROUP BY t.id HAVING c >= 2");
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
    $n = (string)$x['name'];
    if (!isset($have[$n])) $score[$n] = ($score[$n] ?? 0) + (int)$x['c'];
  }
  arsort($score);
  return array_slice(array_keys($score), 0, $limit);
}

/**
 * Coppie di tag che probabilmente sono la stessa cosa: stessa radice
 * (nazista/nazisti), radice molto simile (nazista/nazismo), nome uguale senza
 * spazi (mario scarpati/marioscarpati) o differenza di una lettera (refusi).
 * Ritorna [['from', 'into', 'reason', 'uses']] dove 'into' e' il tag da
 * tenere (manuale e piu' usato).
 */
function nlp_tag_merge_candidates(SQLite3 $db, int $limit = 30): array {
  $tags = [];
  $r = $db->query('SELECT t.id, t.name, t.kind, COUNT(et.entry_id) uses FROM tags t
                   JOIN entry_tags et ON et.tag_id = t.id GROUP BY t.id');
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
    $words = preg_split('/[\s_\-]+/u', (string)$x['name'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $x['key'] = implode(' ', array_map('stem_it', $words));
    $x['flat'] = str_replace([' ', '_', '-'], '', (string)$x['name']);
    $tags[] = $x;
  }
  $buckets = [];
  foreach ($tags as $i => $t) $buckets[mb_substr($t['flat'], 0, 3, 'UTF-8')][] = $i;
  $out = [];
  $dismissed = array_fill_keys(json_decode((string)kv_get($db, 'tag_merge_dismissed', '[]'), true) ?: [], true);
  // nazista / nazismo / nazisti -> "naz": stessa famiglia ideologica
  $ideo = static function (string $w): ?string {
    return preg_match('/^(.{3,}?)(ista|isti|iste|ismo|ismi|istico|istica|istici|istiche)$/u', $w, $m) ? $m[1] : null;
  };
  $prefix = static function (string $a, string $b): int {
    $n = min(mb_strlen($a), mb_strlen($b)); $i = 0;
    while ($i < $n && mb_substr($a, $i, 1) === mb_substr($b, $i, 1)) $i++;
    return $i;
  };
  foreach ($buckets as $ids) {
    $c = count($ids);
    for ($x = 0; $x < $c; $x++) for ($y = $x + 1; $y < $c; $y++) {
      $a = $tags[$ids[$x]]; $b = $tags[$ids[$y]];
      $la = mb_strlen($a['flat']); $lb = mb_strlen($b['flat']);
      $reason = null;
      if (isset($dismissed[$a['name'] . '|' . $b['name']]) || isset($dismissed[$b['name'] . '|' . $a['name']])) continue;
      if ($a['flat'] === $b['flat']) $reason = 'stesso nome';
      elseif ($a['key'] === $b['key']) $reason = 'stessa radice';
      elseif ($ideo($a['flat']) !== null && $ideo($a['flat']) === $ideo($b['flat'])) $reason = 'stessa famiglia';
      elseif (min($la, $lb) >= 5 && $prefix($a['flat'], $b['flat']) === min($la, $lb)
              && (str_contains($a['name'], ' ') || str_contains($b['name'], ' ')
                  || $lb - $la >= 4 || $la - $lb >= 4)) $reason = 'parte del nome';
      elseif (min($la, $lb) >= 6 && levenshtein($a['flat'], $b['flat']) <= 1) $reason = 'quasi uguali';
      if ($reason === null) continue;
      $keepA = [$a['kind'] === 'manual', (int)$a['uses'], -$la] >= [$b['kind'] === 'manual', (int)$b['uses'], -$lb];
      [$into, $from] = $keepA ? [$a, $b] : [$b, $a];
      $out[] = ['from' => $from['name'], 'from_id' => (int)$from['id'], 'into' => $into['name'],
                'into_id' => (int)$into['id'], 'reason' => $reason, 'uses' => (int)$a['uses'] + (int)$b['uses']];
    }
  }
  usort($out, static fn($p, $q) => $q['uses'] <=> $p['uses']);
  return array_slice($out, 0, $limit);
}

/**
 * Fonde il tag $from in $into: sposta le associazioni e, per i tag manuali,
 * riscrive anche gli #hashtag nel testo delle voci (cosi' la fusione
 * sopravvive alle modifiche successive). Ritorna le voci toccate.
 */
function tag_merge(SQLite3 $db, int $from_id, int $into_id, string $editor): int {
  if ($from_id === $into_id) return 0;
  $from = (string)$db->querySingle('SELECT name FROM tags WHERE id = ' . $from_id);
  $into = (string)$db->querySingle('SELECT name FROM tags WHERE id = ' . $into_id);
  if ($from === '' || $into === '') throw new RuntimeException('tag inesistente');
  $ids = [];
  $r = $db->query('SELECT entry_id FROM entry_tags WHERE tag_id = ' . $from_id);
  while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $ids[] = (int)$x[0];
  foreach ($ids as $eid) {
    $raw = (string)$db->querySingle('SELECT raw FROM entries WHERE id = ' . $eid);
    $new = nlp_strip_tag_from_raw($raw, $from);
    if ($new !== $raw) {
      entry_update($db, $eid, nlp_merge_tags_into_raw($new, [$into]), $editor);
    } else {
      $db->exec("INSERT OR IGNORE INTO entry_tags(entry_id, tag_id, auto, weight)
                 SELECT entry_id, $into_id, auto, weight FROM entry_tags WHERE tag_id = $from_id AND entry_id = $eid");
    }
  }
  // il nome scartato diventa un alias: non rinasce ne' come auto-tag ne'
  // da un #hashtag scritto in futuro (prima si spostano gli alias che
  // puntavano a $from, poi si cancella il tag: la FK li eliminerebbe)
  $db->exec('UPDATE tag_aliases SET tag_id = ' . $into_id . ' WHERE tag_id = ' . $from_id);
  $st = $db->prepare('INSERT INTO tag_aliases(alias, tag_id) VALUES(:a, :t)
                      ON CONFLICT(alias) DO UPDATE SET tag_id = excluded.tag_id');
  $st->bindValue(':a', $from, SQLITE3_TEXT);
  $st->bindValue(':t', $into_id, SQLITE3_INTEGER);
  $st->execute();
  $db->exec('DELETE FROM tags WHERE id = ' . $from_id);
  return count($ids);
}

/**
 * Elimina un tag da tutte le voci. Per i tag manuali toglie anche gli
 * #hashtag dal testo: altrimenti rinascerebbero alla modifica successiva.
 * Ritorna le voci toccate.
 */
function tag_delete(SQLite3 $db, int $tid, string $editor): int {
  $name = (string)$db->querySingle('SELECT name FROM tags WHERE id = ' . $tid);
  if ($name === '') return 0;
  $ids = [];
  $r = $db->query('SELECT entry_id FROM entry_tags WHERE tag_id = ' . $tid . ' AND auto = 0');
  while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $ids[] = (int)$x[0];
  foreach ($ids as $eid) {
    $raw = (string)$db->querySingle('SELECT raw FROM entries WHERE id = ' . $eid);
    $new = nlp_strip_tag_from_raw($raw, $name);
    if ($new !== $raw && trim($new) !== '') entry_update($db, $eid, $new, $editor);
  }
  $n = (int)$db->querySingle('SELECT COUNT(*) FROM entry_tags WHERE tag_id = ' . $tid);
  $db->exec('DELETE FROM tags WHERE id = ' . $tid);
  return max($n, count($ids));
}

/* ============================  Rendering  ============================ */

/**
 * Corpo voce -> HTML sicuro, con un Markdown leggero (dal 08/10/2026):
 *   **grassetto**  *corsivo*  _corsivo_  `codice`
 *   righe "- " / "* " -> elenco puntato, "1. " -> elenco numerato
 *   righe "> " -> citazione, "## titolo" -> sottotitolo
 *   URL -> link cliccabili (nuova scheda, senza referrer)
 *   #hashtag -> link al tag, [[123]] / [[slug]] -> link alla voce
 *   riga vuota -> nuovo paragrafo, a capo -> <br>
 * Sicurezza: TUTTO il testo passa da h() prima di qualunque trasformazione;
 * le regole producono solo tag fissi, e gli href degli URL vengono
 * ricostruiti e riescapati (solo http/https).
 */
function entry_render_body(string $body): string {
  $lines = preg_split('/\R/u', $body) ?: [];
  $out = [];
  $para = [];
  $list = null;     // 'ul' | 'ol' | null
  $flush_para = static function () use (&$para, &$out): void {
    if ($para) { $out[] = '<p>' . implode('<br>', $para) . '</p>'; $para = []; }
  };
  $close_list = static function () use (&$list, &$out): void {
    if ($list !== null) { $out[] = "</$list>"; $list = null; }
  };
  foreach ($lines as $ln) {
    $t = rtrim($ln);
    if (trim($t) === '') { $flush_para(); $close_list(); continue; }
    if (preg_match('/^\s*[-*•]\s+(.+)$/u', $t, $m)) {
      $flush_para();
      if ($list !== 'ul') { $close_list(); $out[] = '<ul class="md">'; $list = 'ul'; }
      $out[] = '<li>' . md_inline($m[1]) . '</li>';
      continue;
    }
    if (preg_match('/^\s*\d{1,3}[.)]\s+(.+)$/u', $t, $m)) {
      $flush_para();
      if ($list !== 'ol') { $close_list(); $out[] = '<ol class="md">'; $list = 'ol'; }
      $out[] = '<li>' . md_inline($m[1]) . '</li>';
      continue;
    }
    $close_list();
    if (preg_match('/^\s*>\s?(.*)$/u', $t, $m)) {
      $flush_para();
      $out[] = '<blockquote>' . md_inline($m[1]) . '</blockquote>';
      continue;
    }
    if (preg_match('/^#{2,4}\s+(.+)$/u', $t, $m)) {
      $flush_para();
      $out[] = '<h3 class="md">' . md_inline($m[1]) . '</h3>';
      continue;
    }
    $para[] = md_inline($t);
  }
  $flush_para();
  $close_list();
  // blockquote consecutivi: uno solo
  return str_replace("</blockquote>\n<blockquote>", '<br>', implode("\n", $out));
}

/** Formattazione in linea (vedi entry_render_body). $s e' testo grezzo. */
function md_inline(string $s): string {
  $esc = h($s);
  $keep = [];
  $hold = static function (string $html) use (&$keep): string {
    $keep[] = $html;
    return "\x01" . (count($keep) - 1) . "\x02";
  };
  // `codice` (il contenuto non subisce altre regole)
  $esc = preg_replace_callback('/`([^`\n]+)`/u', static fn($m) => $hold('<code>' . $m[1] . '</code>'), $esc) ?? $esc;
  // URL: href ricostruito dal testo originale e riescapato
  $esc = preg_replace_callback('~https?://[^\s<>"\x01]+~u', static function ($m) use ($hold) {
    $raw = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $trail = '';
    while ($raw !== '' && strpbrk(substr($raw, -1), '.,;:!?)»') !== false) { $trail = substr($raw, -1) . $trail; $raw = substr($raw, 0, -1); }
    if (!filter_var($raw, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $raw)) return $m[0];
    $show = preg_replace('~^https?://(www\.)?~i', '', $raw) ?? $raw;
    if (mb_strlen($show, 'UTF-8') > 60) $show = mb_substr($show, 0, 57, 'UTF-8') . '…';
    return $hold('<a href="' . h($raw) . '" target="_blank" rel="noopener noreferrer nofollow">' . h($show) . '</a>') . h($trail);
  }, $esc) ?? $esc;
  // [[backlink]]
  $esc = preg_replace_callback(
    '~\[\[\s*(\d{1,9}|\d{4}-\d{2}-\d{2}-\d+|\d{1,2}/\d{1,2}/\d{4}-\d+)\s*\]\]~u',
    static fn($m) => $hold('<a href="entry.php?e=' . rawurlencode($m[1]) . '">[[' . $m[1] . ']]</a>'),
    $esc
  ) ?? $esc;
  // #hashtag -> pagina del tag (non dentro le entita' HTML tipo &#039;)
  $esc = preg_replace_callback('/(?<![\w&#\/])#([\p{L}\p{N}][\p{L}\p{N}_\-]{1,39})/u', static function ($m) use ($hold) {
    $n = nlp_tag_normalize(str_replace('_', ' ', $m[1]));
    return $n === '' ? $m[0] : $hold('<a class="hashtag" href="tags.php?tag=' . rawurlencode($n) . '">#' . $m[1] . '</a>');
  }, $esc) ?? $esc;
  // enfasi
  $esc = preg_replace('/\*\*(\S(?:.*?\S)?)\*\*/u', '<strong>$1</strong>', $esc) ?? $esc;
  $esc = preg_replace('/(?<![\w*])\*(\S(?:[^*\n]*?\S)?)\*(?![\w*])/u', '<em>$1</em>', $esc) ?? $esc;
  $esc = preg_replace('/(?<![\p{L}\p{N}_])_(\S(?:[^_\n]*?\S)?)_(?![\p{L}\p{N}_])/u', '<em>$1</em>', $esc) ?? $esc;
  // ripristino dei pezzi protetti
  return preg_replace_callback("/\x01(\d+)\x02/", static fn($m) => $keep[(int)$m[1]], $esc) ?? $esc;
}
