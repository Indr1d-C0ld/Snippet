<?php
declare(strict_types=1);

/**
 * snippet - motore di ricerca condiviso da web (search.php) e bot (/search).
 *
 * Una sola casella, con filtri scritti nel testo:
 *   tag:lavoro   persona:jamal   da:01/09/2026   a:30/09/2026   tema:2
 *   fonte:telegram
 * (le date accettano anche AAAA-MM-GG, MM/AAAA e AAAA; i valori con spazi
 * vanno tra virgolette: persona:"Mario Scarpati").
 *
 * Tre modalita':
 *   parole       full-text FTS5 (sintassi completa: AND OR NOT "frase" pre*)
 *   significato  embedding locale: trova le voci che parlano della stessa
 *                cosa anche con parole diverse
 *   misto        le due classifiche fuse (Reciprocal Rank Fusion): in cima
 *                cio' che e' rilevante per entrambe. Default se il servizio
 *                ML e' attivo.
 */

require_once __DIR__ . '/lib_nlp.php';

const SEARCH_FILTER_KEYS = [
  'tag' => 'tag', 'persona' => 'person', 'p' => 'person', 'da' => 'from', 'dal' => 'from',
  'a' => 'to', 'al' => 'to', 'tema' => 'theme', 'fonte' => 'source',
];

/** Data dei filtri -> [primo giorno, ultimo giorno] 'AAAA-MM-GG', o null. */
function search_date_range(string $v): ?array {
  $v = trim($v);
  if (preg_match('~^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$~', $v, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
    $d = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]); return [$d, $d];
  }
  if (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return [$v, $v];
  if (preg_match('~^(\d{1,2})[/.\-](\d{4})$~', $v, $m) && (int)$m[1] >= 1 && (int)$m[1] <= 12) {
    $a = sprintf('%04d-%02d-01', $m[2], $m[1]);
    return [$a, date('Y-m-t', strtotime($a))];
  }
  if (preg_match('~^(\d{4})$~', $v)) return ["$v-01-01", "$v-12-31"];
  return null;
}

/**
 * Separa testo e filtri. Ritorna ['text', 'tags'[], 'persons'[], 'from',
 * 'to', 'theme', 'source', 'desc' (descrizione leggibile), 'bad'[]].
 */
function search_parse(string $q): array {
  $out = ['text' => '', 'tags' => [], 'persons' => [], 'from' => null, 'to' => null,
          'theme' => null, 'source' => null, 'desc' => '', 'bad' => []];
  $keys = implode('|', array_map('preg_quote', array_keys(SEARCH_FILTER_KEYS)));
  $text = preg_replace_callback('/(?<!\S)(' . $keys . '):(?:"([^"]+)"|(\S+))/iu', static function ($m) use (&$out) {
    $k = SEARCH_FILTER_KEYS[mb_strtolower($m[1], 'UTF-8')];
    $v = trim($m[2] !== '' ? $m[2] : ($m[3] ?? ''));
    switch ($k) {
      case 'tag':    $n = nlp_tag_normalize(str_replace('_', ' ', $v)); if ($n !== '') $out['tags'][] = $n; break;
      case 'person': $out['persons'][] = str_replace('_', ' ', $v); break;
      case 'from':   ($r = search_date_range($v)) ? $out['from'] = $r[0] : $out['bad'][] = $m[0]; break;
      case 'to':     ($r = search_date_range($v)) ? $out['to'] = $r[1] : $out['bad'][] = $m[0]; break;
      case 'theme':  ctype_digit($v) ? $out['theme'] = (int)$v : $out['bad'][] = $m[0]; break;
      case 'source': in_array($v, ['web', 'telegram', 'import'], true) ? $out['source'] = $v : $out['bad'][] = $m[0]; break;
    }
    return ' ';
  }, $q) ?? $q;
  $out['text'] = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
  $d = [];
  foreach ($out['tags'] as $t) $d[] = "tag $t";
  foreach ($out['persons'] as $p) $d[] = "persona $p";
  if ($out['from']) $d[] = 'dal ' . fmt_day($out['from']);
  if ($out['to']) $d[] = 'al ' . fmt_day($out['to']);
  if ($out['theme']) $d[] = 'tema ' . $out['theme'];
  if ($out['source']) $d[] = 'fonte ' . $out['source'];
  $out['desc'] = implode(' · ', $d);
  return $out;
}

/** Condizioni SQL (su alias e) per i filtri. Ritorna [sql[], bind[]]. */
function search_filter_sql(array $p, bool $archived): array {
  $w = []; $b = [];
  if (!$archived) $w[] = 'e.archived = 0';
  foreach ($p['tags'] as $i => $t) {
    $w[] = "e.id IN (SELECT et.entry_id FROM entry_tags et JOIN tags t ON t.id = et.tag_id WHERE t.name = :tag$i)";
    $b[":tag$i"] = [$t, SQLITE3_TEXT];
  }
  foreach ($p['persons'] as $i => $n) {
    $w[] = "e.id IN (SELECT ep.entry_id FROM entry_persons ep JOIN persons pe ON pe.id = ep.person_id
             WHERE pe.name = :per$i COLLATE NOCASE
                OR (',' || replace(pe.aliases, ', ', ',') || ',') LIKE '%,' || :per$i || ',%' COLLATE NOCASE)";
    $b[":per$i"] = [$n, SQLITE3_TEXT];
  }
  if ($p['from']) {
    $w[] = 'e.created_at >= :from';
    $b[':from'] = [(new DateTime($p['from'] . ' 00:00:00', tzobj()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), SQLITE3_TEXT];
  }
  if ($p['to']) {
    $w[] = 'e.created_at <= :to';
    $b[':to'] = [(new DateTime($p['to'] . ' 23:59:59', tzobj()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), SQLITE3_TEXT];
  }
  if ($p['theme']) { $w[] = 'e.cluster = :theme'; $b[':theme'] = [$p['theme'], SQLITE3_INTEGER]; }
  if ($p['source']) { $w[] = 'e.source = :src'; $b[':src'] = [$p['source'], SQLITE3_TEXT]; }
  return [$w, $b];
}

/**
 * Esegue una query FTS5; se la sintassi non e' valida (apostrofi, simboli)
 * riprova con le parole tra virgolette. Ritorna [id => [rank, snip]] in
 * ordine di rilevanza, filtrato.
 */
function search_fts(SQLite3 $db, string $text, array $w, array $b, int $max = 500): array {
  $run = static function (string $match) use ($db, $w, $b, $max): array {
    $cond = array_merge(['entries_fts MATCH :q'], $w);
    $st = $db->prepare('SELECT e.id, snippet(entries_fts, 1, char(2), char(3), \'…\', 20) snip
                        FROM entries_fts JOIN entries e ON e.id = entries_fts.rowid
                        WHERE ' . implode(' AND ', $cond) . ' ORDER BY bm25(entries_fts) LIMIT ' . $max);
    $st->bindValue(':q', $match, SQLITE3_TEXT);
    foreach ($b as $k => [$v, $t]) $st->bindValue($k, $v, $t);
    $out = []; $i = 0;
    $r = $st->execute();
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) $out[(int)$x['id']] = [$i++, (string)$x['snip']];
    return $out;
  };
  try {
    return $run($text);
  } catch (Throwable $e) {
    $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$words) return [];
    return $run(implode(' ', array_map(static fn($x) => '"' . $x . '"', $words)));
  }
}

/** Id delle voci che passano i filtri (per la ricerca semantica). */
function search_filter_ids(SQLite3 $db, array $w, array $b): array {
  $st = $db->prepare('SELECT e.id FROM entries e' . ($w ? ' WHERE ' . implode(' AND ', $w) : ''));
  foreach ($b as $k => [$v, $t]) $st->bindValue($k, $v, $t);
  $out = [];
  $r = $st->execute();
  while ($x = $r->fetchArray(SQLITE3_NUM)) $out[(int)$x[0]] = true;
  return $out;
}

/**
 * Ricerca completa. $mode: auto | parole | significato | misto.
 * Ritorna ['mode' effettiva, 'total', 'items' (righe entries + snip + sim),
 *          'parsed', 'note' (avvisi per l'utente)].
 */
function search_run(SQLite3 $db, string $q, string $mode = 'auto', int $page = 1, int $per = 25,
                    bool $archived = false, string $sort = 'rel'): array {
  $p = search_parse($q);
  [$w, $b] = search_filter_sql($p, $archived);
  $note = $p['bad'] ? 'filtri non riconosciuti: ' . implode(' ', $p['bad']) : '';
  $ml = ml_enabled();
  if ($mode === 'auto' || !in_array($mode, ['parole', 'significato', 'misto'], true)) $mode = $ml ? 'misto' : 'parole';
  if ($mode !== 'parole' && !$ml) { $mode = 'parole'; $note = trim($note . ' · ricerca per significato non disponibile', ' ·'); }

  $order = []; $snips = []; $sims = [];
  if ($p['text'] === '') {
    // solo filtri: elenco cronologico
    $st = $db->prepare('SELECT e.id FROM entries e' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY e.created_at DESC');
    foreach ($b as $k => [$v, $t]) $st->bindValue($k, $v, $t);
    $r = $st->execute();
    while ($x = $r->fetchArray(SQLITE3_NUM)) $order[] = (int)$x[0];
    $mode = 'filtri';
  } else {
    $fts = $mode !== 'significato' ? search_fts($db, $p['text'], $w, $b) : [];
    foreach ($fts as $id => [, $sn]) $snips[$id] = $sn;
    $sem = [];
    if ($mode !== 'parole') {
      $hits = ml_semantic_search($db, $p['text'], 500);
      if ($hits === null) {
        $note = trim($note . ' · servizio ML non raggiungibile: solo parole', ' ·');
        $mode = 'parole';
      } else {
        $allowed = search_filter_ids($db, $w, $b);
        // Soglia relativa alla query: le similarita' di una query breve sono
        // compresse in un intervallo stretto, che cambia da query a query; si
        // tengono le voci sopra media + 1 deviazione standard (calibrato sul
        // diario reale il 08/10/2026), mai sotto un minimo assoluto.
        $v = array_values($hits);
        $n = max(1, count($v));
        $mean = array_sum($v) / $n;
        $sd = sqrt(array_sum(array_map(static fn($x) => ($x - $mean) ** 2, $v)) / $n);
        $floor = max(0.78, $mean + (float)(cfg()['semantic_query_k'] ?? 1.0) * $sd);
        $i = 0;
        foreach ($hits as $id => $c) {
          if (!isset($allowed[$id]) || $c < $floor) continue;
          $sem[$id] = $i++;
          $sims[$id] = $c;
        }
      }
    }
    if ($mode === 'parole') {
      $order = array_keys($fts);
    } elseif ($mode === 'significato') {
      $order = array_keys($sem);
    } else {
      $score = [];
      foreach ($fts as $id => [$rk]) $score[$id] = ($score[$id] ?? 0) + 1 / (60 + $rk);
      foreach ($sem as $id => $rk) $score[$id] = ($score[$id] ?? 0) + 1 / (60 + $rk);
      arsort($score);
      $order = array_keys($score);
    }
    if ($sort === 'date' && $order) {
      $dates = [];
      $r = $db->query('SELECT id, created_at FROM entries WHERE id IN (' . implode(',', array_map('intval', $order)) . ')');
      while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $dates[(int)$x[0]] = (string)$x[1];
      usort($order, static fn($a, $c) => strcmp($dates[$c] ?? '', $dates[$a] ?? ''));
    }
  }

  $total = count($order);
  $slice = array_slice($order, max(0, ($page - 1) * $per), $per);
  $items = [];
  if ($slice) {
    $rows = [];
    $r = $db->query('SELECT * FROM entries WHERE id IN (' . implode(',', array_map('intval', $slice)) . ')');
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $rows[(int)$x['id']] = $x;
    foreach ($slice as $id) {
      if (!isset($rows[$id])) continue;
      $items[] = $rows[$id] + ['snip' => $snips[$id] ?? '', 'sim' => $sims[$id] ?? null];
    }
  }
  return ['mode' => $mode, 'total' => $total, 'items' => $items, 'parsed' => $p, 'note' => $note];
}
