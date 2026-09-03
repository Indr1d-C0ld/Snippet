<?php
declare(strict_types=1);

/**
 * Guardia comune degli endpoint api/: risponde JSON, richiede che la chiamata
 * arrivi da un IP ammesso (il bot gira in locale) e con un bearer token valido
 * uguale a cfg()['ingest_token']. Fallisce con jout() prima di ogni logica.
 */

require_once __DIR__ . '/../lib.php';

function api_json(array $x, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($x, JSON_UNESCAPED_UNICODE);
  exit;
}

(static function (): void {
  $C = cfg();

  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  $allow = (array)($C['ingest_allow_ip'] ?? ['127.0.0.1', '::1']);
  if (!in_array($ip, $allow, true)) {
    api_json(['ok' => false, 'error' => 'IP non ammesso'], 403);
  }

  $tok = (string)($C['ingest_token'] ?? '');
  $hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
  if ($hdr === '' && function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $k => $v) {
      if (strcasecmp((string)$k, 'Authorization') === 0) { $hdr = (string)$v; break; }
    }
  }
  $bearer = preg_match('/^Bearer\s+(.+)$/i', trim($hdr), $m) ? trim($m[1]) : '';

  if ($tok === '' || $tok === 'CHANGE_ME' || $bearer === '' || !hash_equals($tok, $bearer)) {
    api_json(['ok' => false, 'error' => 'token non valido'], 401);
  }
})();
