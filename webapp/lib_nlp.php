<?php
declare(strict_types=1);

/**
 * snippet - pipeline NLP e persistenza delle voci.
 *
 * UNICA implementazione di: parsing direttive, estrazione keyword, auto-tag,
 * correlazioni/backlink. Usata sia dal salvataggio web (compose.php/edit.php)
 * sia dall'ingest Telegram (api/ingest.php). Il motore keyword riprende
 * `extract_keywords()` di RSSIntel/webapp/item.php e la stoplist stopwords.php.
 */

require_once __DIR__ . '/lib.php';

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
      'potere', 'dovere', 'sapere', 'piacere', 'avvenire', 'divenire', 'genere',
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
    ], true);
  }
  if (isset($keep[$w])) return false;
  $len = mb_strlen($w, 'UTF-8');
  if ($len >= 6 && preg_match('/(ando|endo)$/u', $w)) return true;                 // gerundio
  if ($len >= 5 && preg_match('/(are|ere|ire|arsi|ersi|irsi)$/u', $w)) return true; // infinito
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
  if (preg_match('/(avano?|evano?|ivano?|arono|erono|irono|assero|essero|issero|erebbero|irebbero|eremmo|iremmo|erete|irete|avate|evate|ivate|asti|esti|isti|ammo|emmo|immo)$/u', $w)) {
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
 * Ricostruisce keyword, tag ed archi in uscita di una voce. Idempotente.
 * $manual_tags: nomi gia' normalizzati. $mentions: id/slug dai [[..]].
 */
function entry_reindex(SQLite3 $db, int $id, string $body,
                       array $manual_tags, array $mentions, bool $do_links): void {
  $C = cfg();

  /* --- keyword --- */
  $db->exec('DELETE FROM entry_keywords WHERE entry_id = ' . $id);
  $kws = nlp_extract_keywords($body, (int)($C['keywords_per_entry'] ?? 8));
  $ins = $db->prepare('INSERT INTO entry_keywords(entry_id, term, freq, rank) VALUES(:e,:t,:f,:r)');
  $rank = 0;
  foreach ($kws as $k) {
    $rank++;
    $ins->reset();
    $ins->bindValue(':e', $id, SQLITE3_INTEGER);
    $ins->bindValue(':t', $k['term'], SQLITE3_TEXT);
    $ins->bindValue(':f', $k['freq'], SQLITE3_INTEGER);
    $ins->bindValue(':r', $rank, SQLITE3_INTEGER);
    $ins->execute();
  }

  /* --- tag (rebuild completo delle associazioni della voce) --- */
  $db->exec('DELETE FROM entry_tags WHERE entry_id = ' . $id);

  // auto-tag dalle prime keyword
  $auto_n = (int)($C['autotags_per_entry'] ?? 5);
  $auto_terms = [];
  foreach (array_slice($kws, 0, $auto_n) as $k) $auto_terms[$k['term']] = $k['freq'];

  $link_et = $db->prepare(
    'INSERT INTO entry_tags(entry_id, tag_id, auto, weight) VALUES(:e,:t,:a,:w)
     ON CONFLICT(entry_id, tag_id) DO UPDATE SET auto = MIN(entry_tags.auto, excluded.auto)'
  );

  foreach ($auto_terms as $term => $freq) {
    $tid = nlp_tag_id($db, (string)$term, 'auto');
    if ($tid <= 0) continue;
    $link_et->reset();
    $link_et->bindValue(':e', $id, SQLITE3_INTEGER);
    $link_et->bindValue(':t', $tid, SQLITE3_INTEGER);
    $link_et->bindValue(':a', 1, SQLITE3_INTEGER);
    $link_et->bindValue(':w', (float)$freq, SQLITE3_FLOAT);
    $link_et->execute();
  }
  foreach (array_unique($manual_tags) as $name) {
    $name = nlp_tag_normalize((string)$name);
    if ($name === '') continue;
    $tid = nlp_tag_id($db, $name, 'manual');
    if ($tid <= 0) continue;
    $link_et->reset();
    $link_et->bindValue(':e', $id, SQLITE3_INTEGER);
    $link_et->bindValue(':t', $tid, SQLITE3_INTEGER);
    $link_et->bindValue(':a', 0, SQLITE3_INTEGER);
    $link_et->bindValue(':w', 1.0, SQLITE3_FLOAT);
    $link_et->execute();
  }

  /* --- archi in uscita (ricostruiti; gli entranti restano) --- */
  $db->exec('DELETE FROM links WHERE src_id = ' . $id);

  $manual_dst = [];
  foreach ($mentions as $ref) {
    $dst = entry_resolve_ref($db, (string)$ref);
    if ($dst === null || $dst === $id) continue;
    $manual_dst[$dst] = true;
    $st = $db->prepare('INSERT OR IGNORE INTO links(src_id, dst_id, kind, score) VALUES(:s,:d,\'manual\',5)');
    $st->bindValue(':s', $id, SQLITE3_INTEGER);
    $st->bindValue(':d', $dst, SQLITE3_INTEGER);
    $st->execute();
  }

  if (!$do_links) return;

  $shared = []; // other_id => ['kw'=>int,'tag'=>int]
  $q = $db->prepare("
    SELECT ek2.entry_id AS other, COUNT(*) AS n
    FROM entry_keywords ek1
    JOIN entry_keywords ek2 ON ek2.term = ek1.term AND ek2.entry_id <> ek1.entry_id
    WHERE ek1.entry_id = :id
    GROUP BY ek2.entry_id
  ");
  $q->bindValue(':id', $id, SQLITE3_INTEGER);
  $r = $q->execute();
  while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $shared[(int)$row['other']] = ['kw' => (int)$row['n'], 'tag' => 0];
  }

  $q = $db->prepare("
    SELECT et2.entry_id AS other, COUNT(*) AS n
    FROM entry_tags et1
    JOIN entry_tags et2 ON et2.tag_id = et1.tag_id AND et2.entry_id <> et1.entry_id
    WHERE et1.entry_id = :id
    GROUP BY et2.entry_id
  ");
  $q->bindValue(':id', $id, SQLITE3_INTEGER);
  $r = $q->execute();
  while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $o = (int)$row['other'];
    $shared[$o] ??= ['kw' => 0, 'tag' => 0];
    $shared[$o]['tag'] = (int)$row['n'];
  }

  $cands = [];
  foreach ($shared as $other => $s) {
    if (isset($manual_dst[$other])) continue;
    $score = $s['kw'] + 2 * $s['tag'];
    if ($score < (int)($C['correlate_min_score'] ?? 2)) continue;
    $kind = ($s['tag'] > 0 && 2 * $s['tag'] >= $s['kw']) ? 'tag' : 'keyword';
    $cands[] = ['dst' => $other, 'kind' => $kind, 'score' => $score];
  }
  usort($cands, static fn($a, $b) => $b['score'] <=> $a['score']);
  $cands = array_slice($cands, 0, (int)($C['correlate_max_links'] ?? 12));

  $st = $db->prepare('INSERT OR IGNORE INTO links(src_id, dst_id, kind, score) VALUES(:s,:d,:k,:sc)');
  foreach ($cands as $c) {
    $st->reset();
    $st->bindValue(':s', $id, SQLITE3_INTEGER);
    $st->bindValue(':d', $c['dst'], SQLITE3_INTEGER);
    $st->bindValue(':k', $c['kind'], SQLITE3_TEXT);
    $st->bindValue(':sc', (float)$c['score'], SQLITE3_FLOAT);
    $st->execute();
  }
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

  return ['id' => $id, 'slug' => $slug];
}

/**
 * Riprocessa una voce esistente dal suo testo grezzo aggiornato.
 * Lo slug (permalink) NON cambia. Ritorna ['id','slug'].
 */
function entry_update(SQLite3 $db, int $id, string $raw, string $editor): array {
  $cur = $db->querySingle('SELECT slug, created_at FROM entries WHERE id = ' . $id, true);
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
  $st->bindValue(':pin', $d['pinned'] ? 1 : 0, SQLITE3_INTEGER);
  $st->bindValue(':now', now_utc(), SQLITE3_TEXT);
  $st->bindValue(':i', $id, SQLITE3_INTEGER);
  $st->execute();

  entry_reindex($db, $id, $body, $d['tags'], $d['mentions'], !$d['nolink']);

  return ['id' => $id, 'slug' => (string)$cur['slug']];
}

/**
 * Ricostruisce integralmente keyword, tag e archi di TUTTE le voci.
 * Da lanciare dopo import massivi o periodicamente (timer): rende simmetrico
 * il grafo, che il salvataggio della singola voce aggiorna solo in uscita.
 * Ritorna ['entries' => int, 'edges' => int, 'seconds' => float].
 */
function graph_rebuild(SQLite3 $db): array {
  $t0 = microtime(true);
  $rows = [];
  $r = $db->query('SELECT id, body, raw FROM entries ORDER BY id');
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $rows[] = $x;

  $db->exec('BEGIN');
  $db->exec('DELETE FROM links');
  foreach ($rows as $x) {
    $src = ((string)$x['raw'] !== '') ? (string)$x['raw'] : (string)$x['body'];
    $d = nlp_parse_directives($src);
    $body = $d['body'] !== '' ? $d['body'] : (string)$x['body'];
    entry_reindex($db, (int)$x['id'], $body, $d['tags'], $d['mentions'], !$d['nolink']);
  }
  $db->exec('COMMIT');

  return [
    'entries' => count($rows),
    'edges'   => (int)$db->querySingle('SELECT COUNT(*) FROM links'),
    'seconds' => round(microtime(true) - $t0, 2),
  ];
}

/* ============================  Rendering  ============================ */

/**
 * Corpo voce -> HTML sicuro: escape completo, poi i backlink [[..]] diventano
 * link e le newline <br>. (I #hashtag restano testo semplice.)
 */
function entry_render_body(string $body): string {
  $esc = h($body);
  $esc = preg_replace_callback(
    '~\[\[\s*(\d{1,9}|\d{4}-\d{2}-\d{2}-\d+|\d{1,2}/\d{1,2}/\d{4}-\d+)\s*\]\]~u',
    static function ($m) {
      $ref = $m[1];
      return '<a href="entry.php?e=' . rawurlencode($ref) . '">[[' . h($ref) . ']]</a>';
    },
    $esc
  ) ?? $esc;
  return nl2br($esc, false);
}
