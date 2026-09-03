#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("solo da riga di comando\n"); }

/**
 * Genera le icone PWA di snippet in webapp/assets/ (richiede l'estensione GD).
 *   php bin/make_icons.php
 *
 * Motivo grafico: tre nodi collegati (la "mappa" delle voci), bianchi su
 * fondo accent. Rigenera pure con parametri diversi: le uscite sono
 * versionate nel repo cosi' il deploy non dipende da GD.
 */

if (!function_exists('imagecreatetruecolor')) {
  fwrite(STDERR, "GD non disponibile.\n");
  exit(1);
}

$OUT = is_dir(dirname(__DIR__) . '/webapp')
  ? dirname(__DIR__) . '/webapp/assets'
  : dirname(__DIR__) . '/assets';

function draw_icon(int $size, float $motif = 0.68): \GdImage {
  $im = imagecreatetruecolor($size, $size);
  imagealphablending($im, true);
  imageantialias($im, true);

  $bg = imagecolorallocate($im, 0x2f, 0x6f, 0xeb);
  $fg = imagecolorallocate($im, 0xff, 0xff, 0xff);
  imagefilledrectangle($im, 0, 0, $size, $size, $bg);

  $c = $size / 2.0;
  $R = $size * $motif / 2.0;                 // raggio del triangolo dei nodi
  $node = max(6, (int)round($size * 0.085)); // raggio nodo
  $lw = max(3, (int)round($size * 0.035));   // spessore linee

  // tre nodi ai vertici di un triangolo equilatero (punta in alto)
  $pts = [];
  for ($i = 0; $i < 3; $i++) {
    $a = -M_PI / 2 + $i * 2 * M_PI / 3;
    $pts[] = [$c + $R * cos($a), $c + $R * sin($a)];
  }

  imagesetthickness($im, $lw);
  foreach ([[0, 1], [1, 2], [2, 0]] as [$x, $y]) {
    imageline($im, (int)$pts[$x][0], (int)$pts[$x][1], (int)$pts[$y][0], (int)$pts[$y][1], $fg);
  }
  foreach ($pts as $p) {
    imagefilledellipse($im, (int)$p[0], (int)$p[1], $node * 2, $node * 2, $fg);
  }
  return $im;
}

function save(GdImage $im, string $path): void {
  imagepng($im, $path, 6);
  imagedestroy($im);
  echo "  $path\n";
}

@mkdir($OUT, 0775, true);
save(draw_icon(512), "$OUT/icon-512.png");
save(draw_icon(192), "$OUT/icon-192.png");
save(draw_icon(512, 0.46), "$OUT/icon-maskable-512.png"); // extra padding per il safe-zone
echo "fatto.\n";
