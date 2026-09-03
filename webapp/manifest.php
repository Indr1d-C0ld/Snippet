<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/* Manifest servito da PHP: MIME corretto ovunque, senza config del webserver. */
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=86400');

$name = (string)(cfg()['site_name'] ?? 'snippet');

echo json_encode([
  'name'             => $name,
  'short_name'       => 'snippet',
  'description'      => 'Diario e raccolta di pensieri con tagging e correlazione automatici.',
  'lang'            => 'it',
  'start_url'        => 'compose.php',
  'scope'           => './',
  'display'          => 'standalone',
  'orientation'      => 'portrait-primary',
  'theme_color'      => '#2f6feb',
  'background_color' => '#16171a',
  'icons' => [
    ['src' => 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
    ['src' => 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ['src' => 'assets/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
  ],
  'shortcuts' => [
    ['name' => 'Scrivi', 'url' => 'compose.php'],
    ['name' => 'Diario', 'url' => 'diary.php'],
    ['name' => 'Cerca',  'url' => 'search.php'],
  ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
