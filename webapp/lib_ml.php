<?php
declare(strict_types=1);

/**
 * snippet - client del servizio ML locale (ml/snippet_ml.py).
 *
 * Il servizio gira sullo stesso host (127.0.0.1) e fa due cose: calcola i
 * vettori semantici (embedding, modello multilingual-e5-small) e trascrive i
 * vocali (whisper.cpp). Nessun dato esce dal server.
 *
 * Tutto e' facoltativo: senza `ml_url` in config.php, o con il servizio
 * spento, le funzioni ritornano null e snippet lavora come prima (ricerca per
 * parole, correlazioni senza la componente semantica). I vettori mancanti
 * vengono completati dalla manutenzione notturna.
 */

function ml_enabled(): bool {
  return trim((string)(cfg()['ml_url'] ?? '')) !== '';
}

/** POST JSON al servizio ML; null se spento, lento o in errore. */
function ml_call(string $path, array $payload, int $timeout = 20): ?array {
  if (!ml_enabled()) return null;
  static $down_until = 0;
  if (time() < $down_until) return null;   // appena fallito: non insistere in questa richiesta

  $url = rtrim((string)cfg()['ml_url'], '/') . $path;
  $hdr = ['Content-Type: application/json'];
  $tok = (string)(cfg()['ml_token'] ?? '');
  if ($tok !== '') $hdr[] = 'Authorization: Bearer ' . $tok;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => $hdr,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => $timeout,
  ]);
  $res = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  if ($res === false || $code !== 200) { $down_until = time() + 60; return null; }
  $d = json_decode((string)$res, true);
  return is_array($d) && !empty($d['ok']) ? $d : null;
}

/**
 * Vettori normalizzati per i testi dati. $kind: 'passage' per le voci,
 * 'query' per le ricerche (il modello e5 li distingue). null se non disponibile.
 */
function ml_embed(array $texts, string $kind = 'passage'): ?array {
  if (!$texts) return [];
  $d = ml_call('/embed', ['texts' => array_values($texts), 'kind' => $kind], 60);
  if ($d === null || !isset($d['vectors']) || count($d['vectors']) !== count($texts)) return null;
  return $d['vectors'];
}

function ml_model(): string {
  return (string)(cfg()['ml_embed_model'] ?? 'multilingual-e5-small');
}

function vec_pack(array $v): string {
  return pack('g*', ...array_map('floatval', $v));     // float32 little-endian
}

function vec_unpack(string $b): array {
  return array_values(unpack('g*', $b) ?: []);
}

function vec_dot(array $a, array $b): float {
  $s = 0.0;
  $n = min(count($a), count($b));
  for ($i = 0; $i < $n; $i++) $s += $a[$i] * $b[$i];
  return $s;
}

/** Testo che rappresenta una voce per l'embedding (titolo + corpo, troncato). */
function entry_embed_text(?string $title, string $body): string {
  $t = trim((string)$title);
  $b = preg_replace('~https?://\S+~u', ' ', $body) ?? $body;
  $txt = ($t !== '' ? $t . ".\n" : '') . trim($b);
  return mb_substr($txt, 0, 2000, 'UTF-8');   // e5-small legge al massimo ~512 token
}

/**
 * Calcola (o lascia com'e', se il testo non e' cambiato) il vettore di una
 * voce. Ritorna true se il vettore e' presente e aggiornato.
 */
function entry_embed(SQLite3 $db, int $id, ?string $title, string $body): bool {
  $txt = entry_embed_text($title, $body);
  $hash = hash('sha256', ml_model() . "\n" . $txt);
  $cur = $db->querySingle('SELECT text_hash FROM entry_vectors WHERE entry_id = ' . $id);
  if ($cur === $hash) return true;
  $v = ml_embed([$txt], 'passage');
  if ($v === null) return false;
  entry_vector_store($db, $id, $v[0], $hash);
  return true;
}

function entry_vector_store(SQLite3 $db, int $id, array $vec, string $hash): void {
  $st = $db->prepare("INSERT INTO entry_vectors(entry_id, model, dim, vec, text_hash, updated_at)
                      VALUES(:e, :m, :d, :v, :h, datetime('now'))
                      ON CONFLICT(entry_id) DO UPDATE SET model=excluded.model, dim=excluded.dim,
                        vec=excluded.vec, text_hash=excluded.text_hash, updated_at=excluded.updated_at");
  $st->bindValue(':e', $id, SQLITE3_INTEGER);
  $st->bindValue(':m', ml_model(), SQLITE3_TEXT);
  $st->bindValue(':d', count($vec), SQLITE3_INTEGER);
  $st->bindValue(':v', vec_pack($vec), SQLITE3_BLOB);
  $st->bindValue(':h', $hash, SQLITE3_TEXT);
  $st->execute();
}

/**
 * Completa i vettori mancanti o non aggiornati (a lotti). Usata dalla
 * manutenzione e da graph_rebuild(). Ritorna quante voci ha aggiornato,
 * o -1 se il servizio non risponde.
 */
function ml_embed_missing(SQLite3 $db, int $limit = 500): int {
  if (!ml_enabled()) return -1;
  $todo = [];
  $r = $db->query('SELECT e.id, e.title, e.body, v.text_hash FROM entries e
                   LEFT JOIN entry_vectors v ON v.entry_id = e.id ORDER BY e.id');
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
    $txt = entry_embed_text($x['title'], (string)$x['body']);
    $hash = hash('sha256', ml_model() . "\n" . $txt);
    if ($x['text_hash'] !== $hash) $todo[] = [(int)$x['id'], $txt, $hash];
    if (count($todo) >= $limit) break;
  }
  $done = 0;
  foreach (array_chunk($todo, 16) as $chunk) {
    $v = ml_embed(array_column($chunk, 1), 'passage');
    if ($v === null) return $done > 0 ? $done : -1;
    foreach ($chunk as $i => [$id, , $hash]) entry_vector_store($db, $id, $v[$i], $hash);
    $done += count($chunk);
  }
  return $done;
}

/** Tutti i vettori del modello corrente: [entry_id => float[]]. */
function entry_vectors_all(SQLite3 $db): array {
  $out = [];
  $st = $db->prepare('SELECT entry_id, vec FROM entry_vectors WHERE model = :m');
  $st->bindValue(':m', ml_model(), SQLITE3_TEXT);
  $r = $st->execute();
  while ($x = $r->fetchArray(SQLITE3_ASSOC)) $out[(int)$x['entry_id']] = vec_unpack((string)$x['vec']);
  return $out;
}

/**
 * Ricerca semantica: [entry_id => similarita' coseno], in ordine
 * decrescente. null se il servizio non e' disponibile.
 */
function ml_semantic_search(SQLite3 $db, string $q, int $limit = 50): ?array {
  $qv = ml_embed([$q], 'query');
  if ($qv === null) return null;
  $sims = [];
  foreach (entry_vectors_all($db) as $id => $v) $sims[$id] = vec_dot($qv[0], $v);
  arsort($sims);
  return array_slice($sims, 0, $limit, true);
}
