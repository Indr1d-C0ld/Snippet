<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/lib_nlp.php';
require __DIR__ . '/nav.php';
require_login();

$site = (string)(cfg()['site_name'] ?? 'snippet');
$ALL_KINDS = ['manual', 'keyword', 'tag', 'temporal'];

/** kinds da GET: accetta array (checkbox kinds[]) o stringa csv. */
function kinds_from_get($raw, array $all): array {
  if (is_array($raw)) $list = $raw;
  elseif (is_string($raw) && $raw !== '') $list = explode(',', $raw);
  else return $all;
  $list = array_values(array_intersect($all, array_map('trim', $list)));
  return $list ?: $all;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check()) { http_response_code(403); die('CSRF non valido'); }
  if ((string)($_POST['action'] ?? '') === 'rebuild') {
    try {
      $res = graph_rebuild(db_rw());
      flash_set('ok', "Grafo ricostruito: {$res['entries']} voci, {$res['edges']} archi ({$res['seconds']}s).");
    } catch (Throwable $e) {
      flash_set('err', $e->getMessage());
    }
  }
  $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
  header('Location: map.php' . ($qs !== '' ? '?' . $qs : ''));
  exit;
}

$flash = flash_take();

$tag      = nlp_tag_normalize((string)($_GET['tag'] ?? ''));
$from     = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to       = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$minscore = isset($_GET['minscore']) && is_numeric($_GET['minscore']) ? (float)$_GET['minscore'] : (float)(cfg()['correlate_min_score'] ?? 2);
$archived = (int)($_GET['archived'] ?? 0) === 1;
$sel_kinds = kinds_from_get($_GET['kinds'] ?? null, $ALL_KINDS);

$data_qs = http_build_query(array_filter([
  'tag'      => $tag,
  'from'     => $from,
  'to'       => $to,
  'minscore' => $minscore,
  'archived' => $archived ? 1 : '',
  'kinds'    => implode(',', $sel_kinds),
  'limit'    => 800,
], static fn($v) => $v !== '' && $v !== null));
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<meta name="theme-color" content="#2f6feb">
<link rel="manifest" href="manifest.php">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script src="assets/pwa.js" defer></script>
<title><?=h($site)?> — Mappa</title>

<?php render_header('Mappa', 'map'); ?>

<div class="wrap">
  <?php if ($flash): ?>
    <div class="card"><b><?= $flash[0] === 'ok' ? 'OK:' : 'Errore:' ?></b> <?=h((string)$flash[1])?></div>
  <?php endif; ?>

  <form method="get" class="card">
    <div class="row">
      <input class="grow" name="tag" value="<?=h($tag)?>" placeholder="Filtra per tag">
      <input type="date" name="from" value="<?=h($from)?>" title="dal">
      <input type="date" name="to" value="<?=h($to)?>" title="al">
      <label class="meta">punteggio min
        <input name="minscore" value="<?=h((string)$minscore)?>" style="width:70px" inputmode="decimal">
      </label>
      <label class="meta" style="display:flex;align-items:center;gap:6px">
        <input type="checkbox" name="archived" value="1" <?= $archived ? 'checked' : '' ?> style="width:auto"> archiviate
      </label>
      <button class="btn" type="submit">Applica</button>
    </div>
    <div class="row" style="margin-top:8px">
      <span class="meta">tipi di arco:</span>
      <?php foreach (['manual' => 'espliciti [[..]]', 'keyword' => 'keyword', 'tag' => 'tag', 'temporal' => 'temporali'] as $k => $lab): ?>
        <label class="meta" style="display:flex;align-items:center;gap:4px">
          <input type="checkbox" name="kinds[]" value="<?=$k?>" <?= in_array($k, $sel_kinds, true) ? 'checked' : '' ?> style="width:auto"> <?=$lab?>
        </label>
      <?php endforeach; ?>
    </div>
  </form>

  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div class="meta" id="map-status">Carico il grafo…</div>
      <div class="btns">
        <button class="btn" type="button" id="btn-fit">Ricentra</button>
        <button class="btn" type="button" id="btn-freeze">Pausa</button>
        <form method="post" style="display:inline"
              action="map.php<?= $data_qs !== '' ? '?' . h($data_qs) : '' ?>"
              onsubmit="return confirm('Ricalcolare keyword, tag e archi di TUTTE le voci?')">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="rebuild">
          <button class="btn" type="submit">Ricostruisci grafo</button>
        </form>
      </div>
    </div>

    <div id="map-host" style="margin-top:10px; border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; background:var(--bg)">
      <canvas id="map-canvas" style="display:block; width:100%; height:66vh; touch-action:none"></canvas>
    </div>

    <div class="row" style="margin-top:8px">
      <span class="meta">Legenda:</span>
      <span class="badge" style="border-color:var(--accent);color:var(--accent)">espliciti</span>
      <span class="badge">tag</span>
      <span class="badge">keyword</span>
      <span class="meta">· nodo grande = piu' collegato · anello = voce isolata · 📌 fissata</span>
    </div>
    <div class="meta" style="margin-top:6px">
      Trascina un nodo per bloccarlo · rotellina / pizzico per zoom · trascina lo sfondo per spostarti · click su un nodo per aprire la voce.
    </div>
  </div>
</div>

<script>window.SNIPPET_MAP_DATA_URL = 'map_data.php<?= $data_qs !== '' ? '?' . h($data_qs) : '' ?>';</script>
<script src="assets/map.js"></script>
