<?php
declare(strict_types=1);

/**
 * snippet - anteprime dei link citati nelle voci.
 *
 * Molte voci commentano una notizia: di ogni URL si salvano titolo, sito,
 * descrizione e un estratto del testo principale, cosi' il contesto resta
 * anche se la pagina originale sparisce (link-rot).
 *
 * Il download avviene SOLO su richiesta (bot dopo il salvataggio, web dopo
 * compose/edit, manutenzione notturna), mai durante l'indicizzazione, e con
 * protezioni anti-SSRF: solo http/https, l'host deve risolvere a un IP
 * pubblico (niente rete locale, loopback, link-local), l'IP verificato viene
 * imposto a curl (niente DNS rebinding), redirect seguiti a mano e
 * ricontrollati, al massimo 2 MB e 8 secondi.
 */

const LINK_MAX_BYTES = 2 * 1024 * 1024;
const LINK_TIMEOUT = 8;
const LINK_MAX_REDIRECTS = 4;

/** URL http(s) presenti nel testo, senza duplicati e senza punteggiatura finale. */
function links_extract(string $text): array {
  if (!preg_match_all('~https?://[^\s<>"\'\]\[]+~iu', $text, $m)) return [];
  $out = [];
  foreach ($m[0] as $u) {
    $u = rtrim($u, '.,;:!?)»”’');
    if (strlen($u) <= 2048 && filter_var($u, FILTER_VALIDATE_URL)) $out[$u] = true;
  }
  return array_keys($out);
}

/** Allinea link_previews agli URL presenti ora nel testo della voce. */
function links_register(SQLite3 $db, int $id, string $body): void {
  $urls = links_extract($body);
  $keep = array_fill_keys($urls, true);
  $r = $db->query('SELECT url FROM link_previews WHERE entry_id = ' . $id);
  $gone = [];
  while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) if (!isset($keep[(string)$x[0]])) $gone[] = (string)$x[0];
  $del = $db->prepare('DELETE FROM link_previews WHERE entry_id = :e AND url = :u');
  foreach ($gone as $u) {
    $del->reset(); $del->bindValue(':e', $id, SQLITE3_INTEGER); $del->bindValue(':u', $u, SQLITE3_TEXT); $del->execute();
  }
  $ins = $db->prepare('INSERT OR IGNORE INTO link_previews(entry_id, url) VALUES(:e, :u)');
  foreach ($urls as $u) {
    $ins->reset(); $ins->bindValue(':e', $id, SQLITE3_INTEGER); $ins->bindValue(':u', $u, SQLITE3_TEXT); $ins->execute();
  }
}

/** L'IP e' pubblico (non privato, riservato, loopback, link-local)? */
function link_ip_public(string $ip): bool {
  return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
      && !preg_match('/^(0\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|169\.254\.|fe80:|fc|fd|::ffff:)/i', $ip);
}

/**
 * Scarica una pagina rispettando i vincoli anti-SSRF.
 * Ritorna ['body', 'type', 'url' (finale)] o lancia RuntimeException.
 */
function link_http_get(string $url): array {
  for ($hop = 0; $hop <= LINK_MAX_REDIRECTS; $hop++) {
    $p = parse_url($url);
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    $host = (string)($p['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') throw new RuntimeException('URL non valido');
    $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, [80, 443, 8080, 8443], true)) throw new RuntimeException('porta non ammessa');

    $h = trim($host, '[]');
    $ips = filter_var($h, FILTER_VALIDATE_IP) ? [$h] : array_merge(
      array_column(@dns_get_record($h, DNS_A) ?: [], 'ip'),
      array_column(@dns_get_record($h, DNS_AAAA) ?: [], 'ipv6'));
    if (!$ips) throw new RuntimeException('host non risolto');
    foreach ($ips as $ip) if (!link_ip_public((string)$ip)) throw new RuntimeException('indirizzo non pubblico');
    $ip = (string)$ips[0];

    $buf = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? "[$ip]" : $ip)],
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_RETURNTRANSFER => false,
      CURLOPT_HEADER => false,
      CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_TIMEOUT => LINK_TIMEOUT,
      CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
      CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; snippet-preview/1.0)',
      CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', 'Accept-Language: it,en;q=0.8'],
      CURLOPT_ENCODING => '',
      CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$buf) {
        $buf .= $chunk;
        return strlen($buf) > LINK_MAX_BYTES ? 0 : strlen($chunk);   // oltre il tetto: interrompe
      },
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($code >= 300 && $code < 400 && $loc !== '') { $url = $loc; continue; }
    if ($err !== 0 && $err !== CURLE_WRITE_ERROR) throw new RuntimeException('rete: errore ' . $err);
    if ($code !== 200) throw new RuntimeException('HTTP ' . $code);
    return ['body' => $buf, 'type' => $type, 'url' => $url];
  }
  throw new RuntimeException('troppi redirect');
}

/** Titolo, descrizione, sito ed estratto da una pagina HTML. */
function link_parse_html(string $html, string $url): array {
  $out = ['title' => '', 'description' => '', 'site' => (string)(parse_url($url, PHP_URL_HOST) ?? ''), 'excerpt' => ''];
  if (!preg_match('/charset=["\']?utf-?8/i', substr($html, 0, 4096)) && !mb_check_encoding($html, 'UTF-8')) {
    $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
  }
  $dom = new DOMDocument();
  libxml_use_internal_errors(true);
  $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
  libxml_clear_errors();
  $xp = new DOMXPath($dom);
  $meta = static function (string $q) use ($xp): string {
    $n = $xp->query($q);
    return ($n && $n->length) ? trim((string)$n->item(0)->getAttribute('content')) : '';
  };
  $out['title'] = $meta('//meta[@property="og:title"]') ?: trim((string)($xp->query('//title')->item(0)?->textContent ?? ''));
  $out['description'] = $meta('//meta[@property="og:description"]') ?: $meta('//meta[@name="description"]');
  $out['site'] = $meta('//meta[@property="og:site_name"]') ?: $out['site'];

  foreach (['script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside', 'form', 'iframe', 'svg'] as $tag) {
    foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $n) $n->parentNode?->removeChild($n);
  }
  $root = $xp->query('//article')->item(0) ?? $xp->query('//main')->item(0) ?? $dom->getElementsByTagName('body')->item(0);
  $parts = [];
  $len = 0;
  if ($root) {
    foreach ($xp->query('.//p', $root) as $p) {
      $t = trim(preg_replace('/\s+/u', ' ', (string)$p->textContent) ?? '');
      if (mb_strlen($t, 'UTF-8') < 60) continue;
      $parts[] = $t;
      $len += mb_strlen($t, 'UTF-8');
      if ($len > 4000) break;
    }
  }
  $out['excerpt'] = mb_substr(implode("\n\n", $parts), 0, 4000, 'UTF-8');
  foreach (['title' => 300, 'description' => 600, 'site' => 120] as $k => $max) {
    $out[$k] = mb_substr(trim(html_entity_decode($out[$k], ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, $max, 'UTF-8');
  }
  return $out;
}

/**
 * Scarica le anteprime in attesa (di una voce, o di tutte). Ritorna
 * ['ok' => n, 'error' => n]. Non fa nulla se cfg()['link_fetch'] === false.
 */
function links_fetch_pending(SQLite3 $db, ?int $entry_id = null, int $limit = 20): array {
  $res = ['ok' => 0, 'error' => 0];
  if ((cfg()['link_fetch'] ?? true) === false) return $res;
  $where = "status = 'pending'" . ($entry_id !== null ? ' AND entry_id = ' . $entry_id : '');
  $rows = [];
  $r = $db->query("SELECT id, url FROM link_previews WHERE $where ORDER BY id LIMIT " . $limit);
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $rows[] = $x;
  $upd = $db->prepare("UPDATE link_previews SET status = :s, title = :t, description = :d, site = :si,
                       excerpt = :x, error = :er, fetched_at = datetime('now') WHERE id = :i");
  foreach ($rows as $x) {
    $data = ['title' => '', 'description' => '', 'site' => (string)(parse_url($x['url'], PHP_URL_HOST) ?? ''), 'excerpt' => ''];
    $status = 'ok'; $err = null;
    try {
      $g = link_http_get((string)$x['url']);
      if (preg_match('~text/html|application/xhtml~i', $g['type'])) {
        $data = link_parse_html($g['body'], $g['url']);
      } else {
        $data['title'] = basename((string)(parse_url($g['url'], PHP_URL_PATH) ?: $g['url']));
        $data['description'] = trim(explode(';', $g['type'])[0]);
      }
      $res['ok']++;
    } catch (Throwable $e) {
      $status = 'error'; $err = mb_substr($e->getMessage(), 0, 200, 'UTF-8');
      $res['error']++;
    }
    $upd->reset();
    $upd->bindValue(':s', $status, SQLITE3_TEXT);
    $upd->bindValue(':t', $data['title'], SQLITE3_TEXT);
    $upd->bindValue(':d', $data['description'], SQLITE3_TEXT);
    $upd->bindValue(':si', $data['site'], SQLITE3_TEXT);
    $upd->bindValue(':x', $data['excerpt'], SQLITE3_TEXT);
    $upd->bindValue(':er', $err, $err === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $upd->bindValue(':i', (int)$x['id'], SQLITE3_INTEGER);
    $upd->execute();
  }
  return $res;
}

/** Anteprime di una voce (solo quelle scaricate). */
function links_for_entry(SQLite3 $db, int $id): array {
  $out = [];
  $r = $db->query('SELECT url, status, title, description, site, excerpt, fetched_at FROM link_previews
                   WHERE entry_id = ' . $id . ' ORDER BY id');
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $out[] = $x;
  return $out;
}
