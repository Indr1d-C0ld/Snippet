<?php
declare(strict_types=1);

/**
 * snippet - stemmer italiano (algoritmo Snowball, https://snowballstem.org/
 * algorithms/italian/stemmer.html), in PHP puro e senza dipendenze.
 *
 * Riduce le parole alla radice: "nazisti", "nazista" -> "nazist";
 * "lavorando", "lavoro", "lavori" -> "lavor". Serve a far coincidere le forme
 * flesse nel conteggio dei termini (TF-IDF), nelle correlazioni fra voci e
 * nel confronto fra tag. La radice non si mostra mai all'utente: per la
 * visualizzazione si usa la forma reale piu' frequente.
 *
 * Verificato parola per parola contro l'implementazione di riferimento
 * (tests/test_nlp.php, oracolo snowballstemmer).
 */

const STEM_VOWELS = ['a' => 1, 'e' => 1, 'i' => 1, 'o' => 1, 'u' => 1,
                     'à' => 1, 'è' => 1, 'ì' => 1, 'ò' => 1, 'ù' => 1];

function stem_it(string $word): string {
  static $cache = [];
  $word = mb_strtolower($word, 'UTF-8');
  if (isset($cache[$word])) return $cache[$word];
  if (count($cache) > 20000) $cache = [];
  return $cache[$word] = _stem_it($word);
}

/** @internal */
function _stem_it(string $word): string {
  // --- prelude: accenti acuti -> gravi, qu -> qU, u/i fra vocali -> U/I ---
  $word = strtr($word, ['á' => 'à', 'é' => 'è', 'í' => 'ì', 'ó' => 'ò', 'ú' => 'ù']);
  $w = mb_str_split($word, 1, 'UTF-8');
  $n = count($w);
  for ($i = 1; $i < $n; $i++) {
    if ($w[$i] === 'u' && $w[$i - 1] === 'q') $w[$i] = 'U';
  }
  $isv = static fn(array $w, int $i): bool => isset($w[$i]) && isset(STEM_VOWELS[$w[$i]]);
  for ($i = 1; $i < $n - 1; $i++) {
    if (($w[$i] === 'u' || $w[$i] === 'i') && $isv($w, $i - 1) && $isv($w, $i + 1)) {
      $w[$i] = strtoupper($w[$i]);
      $i += 2;   // come "goto (v [u] v)" in Snowball: il cursore riparte dopo
                 // la seconda vocale, che quindi non apre un nuovo match
    }
  }

  // --- regioni RV, R1, R2 ---
  $pV = $p1 = $p2 = $n;
  if ($n >= 2) {
    if ($isv($w, 0)) {
      if (!$isv($w, 1)) {                       // V C ... -> dopo la vocale successiva
        for ($i = 2; $i < $n; $i++) if ($isv($w, $i)) { $pV = $i + 1; break; }
      } else {                                  // V V ... -> dopo la consonante successiva
        for ($i = 2; $i < $n; $i++) if (!$isv($w, $i)) { $pV = $i + 1; break; }
      }
    } else {
      if (!$isv($w, 1)) {                       // C C ... -> dopo la vocale successiva
        for ($i = 2; $i < $n; $i++) if ($isv($w, $i)) { $pV = $i + 1; break; }
      } else {                                  // C V ... -> dalla terza lettera
        $pV = min(3, $n);
      }
    }
  }
  $region = static function (int $from) use ($w, $n, $isv): int {
    for ($i = $from; $i < $n - 1; $i++) {
      if ($isv($w, $i) && !$isv($w, $i + 1)) return $i + 2;
    }
    return $n;
  };
  $p1 = $region(0);
  $p2 = $p1 < $n ? $region($p1) : $n;

  $s = implode('', $w);

  // --- step 0: pronome enclitico dopo gerundio / infinito ---
  static $pron = ['gliela', 'gliele', 'glieli', 'glielo', 'gliene',
                  'sene', 'mela', 'mele', 'meli', 'melo', 'mene', 'tela', 'tele', 'teli', 'telo', 'tene',
                  'cela', 'cele', 'celi', 'celo', 'cene', 'vela', 'vele', 'veli', 'velo', 'vene',
                  'gli', 'ci', 'la', 'le', 'li', 'lo', 'mi', 'ne', 'si', 'ti', 'vi'];
  $p = _stem_longest($s, $pron);
  if ($p !== null) {
    $base = mb_substr($s, 0, mb_strlen($s) - mb_strlen($p));
    $end = _stem_longest($base, ['ando', 'endo', 'ar', 'er', 'ir']);
    if ($end !== null && mb_strlen($base) - mb_strlen($end) >= $pV) {
      $s = in_array($end, ['ando', 'endo'], true) ? $base : $base . 'e';
    }
  }

  $len = static fn(string $x): int => mb_strlen($x, 'UTF-8');
  $cut = static fn(string $x, string $suf): string => mb_substr($x, 0, $len($x) - $len($suf));
  $start = static fn(string $x, string $suf): int => $len($x) - $len($suf);

  // --- step 1: suffissi "standard" (nomi/aggettivi) ---
  static $std = [
    'anza', 'anze', 'ico', 'ici', 'ica', 'ice', 'iche', 'ichi', 'ismo', 'ismi', 'abile', 'abili',
    'ibile', 'ibili', 'ista', 'iste', 'isti', 'istà', 'istè', 'istì', 'oso', 'osi', 'osa', 'ose',
    'mente', 'atrice', 'atrici', 'ante', 'anti',
    'azione', 'azioni', 'atore', 'atori',
    'logia', 'logie', 'uzione', 'uzioni', 'usione', 'usioni', 'enza', 'enze',
    'amento', 'amenti', 'imento', 'imenti', 'amente', 'ità', 'ivo', 'ivi', 'iva', 'ive',
  ];
  $done1 = false;
  $suf = _stem_longest($s, $std);
  if ($suf !== null) {
    $st = $start($s, $suf);
    switch (true) {
      case in_array($suf, ['azione', 'azioni', 'atore', 'atori'], true):
        if ($st >= $p2) {
          $s = $cut($s, $suf); $done1 = true;
          if (str_ends_with($s, 'ic') && $start($s, 'ic') >= $p2) $s = $cut($s, 'ic');
        }
        break;
      case in_array($suf, ['logia', 'logie'], true):
        if ($st >= $p2) { $s = $cut($s, $suf) . 'log'; $done1 = true; }
        break;
      case in_array($suf, ['uzione', 'uzioni', 'usione', 'usioni'], true):
        if ($st >= $p2) { $s = $cut($s, $suf) . 'u'; $done1 = true; }
        break;
      case in_array($suf, ['enza', 'enze'], true):
        if ($st >= $p2) { $s = $cut($s, $suf) . 'ente'; $done1 = true; }
        break;
      case in_array($suf, ['amento', 'amenti', 'imento', 'imenti'], true):
        if ($st >= $pV) { $s = $cut($s, $suf); $done1 = true; }
        break;
      case $suf === 'amente':
        if ($st >= $p1) {
          $s = $cut($s, $suf); $done1 = true;
          $x = _stem_longest($s, ['abil', 'iv', 'os', 'ic']);
          if ($x !== null && $start($s, $x) >= $p2) {
            $s = $cut($s, $x);
            if ($x === 'iv' && str_ends_with($s, 'at') && $start($s, 'at') >= $p2) $s = $cut($s, 'at');
          }
        }
        break;
      case $suf === 'ità':
        if ($st >= $p2) {
          $s = $cut($s, $suf); $done1 = true;
          $x = _stem_longest($s, ['abil', 'ic', 'iv']);
          if ($x !== null && $start($s, $x) >= $p2) $s = $cut($s, $x);
        }
        break;
      case in_array($suf, ['ivo', 'ivi', 'iva', 'ive'], true):
        if ($st >= $p2) {
          $s = $cut($s, $suf); $done1 = true;
          if (str_ends_with($s, 'at') && $start($s, 'at') >= $p2) {
            $s = $cut($s, 'at');
            if (str_ends_with($s, 'ic') && $start($s, 'ic') >= $p2) $s = $cut($s, 'ic');
          }
        }
        break;
      default:   // anza ... anti: semplice cancellazione in R2
        if ($st >= $p2) { $s = $cut($s, $suf); $done1 = true; }
    }
  }

  // --- step 2: desinenze verbali (solo se lo step 1 non ha tolto nulla) ---
  if (!$done1) {
    static $verb = [
      'ammo', 'ando', 'ano', 'are', 'arono', 'asse', 'assero', 'assi', 'assimo', 'ata', 'ate', 'ati',
      'ato', 'ava', 'avamo', 'avano', 'avate', 'avi', 'avo', 'emmo', 'enda', 'ende', 'endi', 'endo',
      'erà', 'erai', 'eranno', 'ere', 'erebbe', 'erebbero', 'erei', 'eremmo', 'eremo', 'ereste',
      'eresti', 'erete', 'erò', 'erono', 'essero', 'ete', 'eva', 'evamo', 'evano', 'evate', 'evi',
      'evo', 'Yamo', 'iamo', 'immo', 'irà', 'irai', 'iranno', 'ire', 'irebbe', 'irebbero', 'irei',
      'iremmo', 'iremo', 'ireste', 'iresti', 'irete', 'irò', 'irono', 'isca', 'iscano', 'isce',
      'isci', 'isco', 'iscono', 'issero', 'ita', 'ite', 'iti', 'ito', 'iva', 'ivamo', 'ivano',
      'ivate', 'ivi', 'ivo', 'ono', 'uta', 'ute', 'uti', 'uto', 'ar', 'ir',
    ];
    // il suffisso deve stare interamente in RV
    $rv = mb_substr($s, min($pV, $len($s)));
    $x = _stem_longest($rv, $verb);
    if ($x !== null) $s = $cut($s, $x);
  }

  // --- step 3a: vocale finale (e una 'i' precedente) in RV ---
  $last = mb_substr($s, -1);
  if ($last !== '' && in_array($last, ['a', 'e', 'i', 'o', 'à', 'è', 'ì', 'ò'], true)
      && $len($s) - 1 >= $pV) {
    $s = mb_substr($s, 0, -1);
    if (str_ends_with($s, 'i') && $len($s) - 1 >= $pV) $s = mb_substr($s, 0, -1);
  }
  // --- step 3b: ch/gh finali -> c/g se in RV ---
  if ((str_ends_with($s, 'ch') || str_ends_with($s, 'gh')) && $len($s) - 2 >= $pV) {
    $s = mb_substr($s, 0, -1);
  }

  // --- postlude ---
  return strtr($s, ['I' => 'i', 'U' => 'u']);
}

/** Suffisso piu' lungo di $list con cui termina $s, o null. */
function _stem_longest(string $s, array $list): ?string {
  $best = null;
  $bl = 0;
  foreach ($list as $suf) {
    $l = strlen($suf);
    if ($l > $bl && str_ends_with($s, $suf)) { $best = $suf; $bl = $l; }
  }
  return $best;
}
