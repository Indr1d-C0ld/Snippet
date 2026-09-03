<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/lib_nlp.php';
require __DIR__ . '/nav.php';
require_login();

$site = (string)(cfg()['site_name'] ?? 'snippet');

/* ===================== POST ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check()) { http_response_code(403); die('CSRF non valido'); }
  $action = (string)($_POST['action'] ?? '');
  $ret = (string)($_POST['ret'] ?? 'tags.php');
  if (!str_starts_with($ret, 'tags.php')) $ret = 'tags.php';

  try {
    $db = db_rw();

    if ($action === 'rename') {
      $id = (int)($_POST['id'] ?? 0);
      $new = nlp_tag_normalize((string)($_POST['name'] ?? ''));
      if ($id <= 0 || $new === '') throw new RuntimeException('id o nuovo nome non validi.');
      $target = (int)$db->querySingle('SELECT id FROM tags WHERE name=' . "'" . SQLite3::escapeString($new) . "'");
      if ($target > 0 && $target !== $id) {
        // merge id -> target
        $st = $db->prepare(
          'INSERT OR IGNORE INTO entry_tags(entry_id, tag_id, auto, weight)
           SELECT entry_id, :tgt, auto, weight FROM entry_tags WHERE tag_id = :src'
        );
        $st->bindValue(':tgt', $target, SQLITE3_INTEGER);
        $st->bindValue(':src', $id, SQLITE3_INTEGER);
        $st->execute();
        $db->exec('DELETE FROM tags WHERE id=' . $id);
        flash_set('ok', 'Tag unito in «' . $new . '».');
      } else {
        $st = $db->prepare('UPDATE tags SET name=:n WHERE id=:i');
        $st->bindValue(':n', $new, SQLITE3_TEXT);
        $st->bindValue(':i', $id, SQLITE3_INTEGER);
        $st->execute();
        flash_set('ok', 'Tag rinominato in «' . $new . '».');
      }
    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      $db->exec('DELETE FROM tags WHERE id=' . $id);
      flash_set('ok', 'Tag eliminato.');
    } elseif ($action === 'kind') {
      $id = (int)($_POST['id'] ?? 0);
      $to = (string)($_POST['to'] ?? '');
      if (!in_array($to, ['manual', 'auto'], true)) throw new RuntimeException('valore non valido.');
      $a = $to === 'auto' ? 1 : 0;
      $db->exec("UPDATE tags SET kind='" . $to . "' WHERE id=" . $id);
      $db->exec('UPDATE entry_tags SET auto=' . $a . ' WHERE tag_id=' . $id);
      flash_set('ok', 'Tag marcato come ' . $to . '.');
    } elseif ($action === 'ignore') {
      $id = (int)($_POST['id'] ?? 0);
      $name = (string)$db->querySingle('SELECT name FROM tags WHERE id=' . $id);
      if ($name !== '') {
        $st = $db->prepare('INSERT OR IGNORE INTO stopwords_custom(word) VALUES(:w)');
        $st->bindValue(':w', mb_strtolower($name, 'UTF-8'), SQLITE3_TEXT);
        $st->execute();
        $db->exec('DELETE FROM tags WHERE id=' . $id);
        flash_set('ok', '«' . $name . '» eliminato e aggiunto alle stopword. Ricostruisci il grafo per riapplicare a tutte le voci.');
      }
    } elseif ($action === 'sw_add') {
      $w = mb_strtolower(trim((string)($_POST['word'] ?? '')), 'UTF-8');
      $w = preg_replace('/[^\p{L}\p{N}\-_]/u', '', $w) ?? '';
      if ($w === '') throw new RuntimeException('parola non valida.');
      $st = $db->prepare('INSERT OR IGNORE INTO stopwords_custom(word) VALUES(:w)');
      $st->bindValue(':w', $w, SQLITE3_TEXT);
      $st->execute();
      flash_set('ok', 'Stopword «' . $w . '» aggiunta.');
    } elseif ($action === 'sw_del') {
      $w = (string)($_POST['word'] ?? '');
      $st = $db->prepare('DELETE FROM stopwords_custom WHERE word=:w');
      $st->bindValue(':w', $w, SQLITE3_TEXT);
      $st->execute();
      flash_set('ok', 'Stopword rimossa.');
    } elseif ($action === 'rebuild') {
      $res = graph_rebuild($db);
      flash_set('ok', "Ricostruito: {$res['entries']} voci, {$res['edges']} archi.");
    }
  } catch (Throwable $e) {
    flash_set('err', $e->getMessage());
  }
  header('Location: ' . $ret);
  exit;
}

$flash = flash_take();
$db = db_ro();
$focus = nlp_tag_normalize((string)($_GET['tag'] ?? ''));

/* ===================== dettaglio di un tag ===================== */
if ($focus !== '') {
  $trow = $db->querySingle("SELECT id, name, kind FROM tags WHERE name='" . SQLite3::escapeString($focus) . "'", true);
  if (!$trow) { http_response_code(404); die('Tag non trovato: ' . h($focus)); }
  $tid = (int)$trow['id'];

  $entries = [];
  $r = $db->query(
    "SELECT e.id, e.slug, e.title, e.body, e.created_at, e.source
     FROM entry_tags et JOIN entries e ON e.id = et.entry_id
     WHERE et.tag_id = $tid ORDER BY e.created_at DESC"
  );
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $entries[] = $x;

  $cooc = [];
  $r = $db->query("
    SELECT t2.name, COUNT(*) AS c
    FROM entry_tags et1
    JOIN entry_tags et2 ON et2.entry_id = et1.entry_id AND et2.tag_id <> et1.tag_id
    JOIN tags t2 ON t2.id = et2.tag_id
    WHERE et1.tag_id = $tid
    GROUP BY t2.name ORDER BY c DESC, t2.name LIMIT 25
  ");
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $cooc[] = $x;
  ?>
  <!doctype html>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="assets/style.css">
<meta name="theme-color" content="#2f6feb">
<link rel="manifest" href="manifest.php">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script src="assets/pwa.js" defer></script>
  <title><?=h($site)?> — Tag <?=h($focus)?></title>
  <?php render_header('Tag: ' . $focus, 'tags'); ?>
  <div class="wrap">
    <?php if ($flash): ?><div class="card"><b><?= $flash[0] === 'ok' ? 'OK:' : 'Errore:' ?></b> <?=h((string)$flash[1])?></div><?php endif; ?>

    <div class="card">
      <div class="row" style="justify-content:space-between">
        <b><?=h($focus)?> <span class="meta">(<?=h((string)$trow['kind'])?>, <?=count($entries)?> voci)</span></b>
        <a class="btn" href="tags.php">← tutti i tag</a>
      </div>
      <hr>
      <div class="btns">
        <form method="post" class="row" style="gap:6px">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="rename">
          <input type="hidden" name="id" value="<?=$tid?>">
          <input type="hidden" name="ret" value="tags.php">
          <input name="name" placeholder="nuovo nome (unisce se esiste)" required>
          <button class="btn" type="submit">Rinomina / unisci</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="kind">
          <input type="hidden" name="id" value="<?=$tid?>">
          <input type="hidden" name="to" value="<?= $trow['kind'] === 'auto' ? 'manual' : 'auto' ?>">
          <input type="hidden" name="ret" value="tags.php?tag=<?=urlencode($focus)?>">
          <button class="btn" type="submit">Segna come <?= $trow['kind'] === 'auto' ? 'manuale' : 'auto' ?></button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('Eliminare il tag «<?=h($focus)?>» da tutte le voci?')">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?=$tid?>">
          <input type="hidden" name="ret" value="tags.php">
          <button class="btn" type="submit">Elimina</button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('Eliminare «<?=h($focus)?>» e aggiungerlo alle stopword?')">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="ignore">
          <input type="hidden" name="id" value="<?=$tid?>">
          <input type="hidden" name="ret" value="tags.php">
          <button class="btn" type="submit">Ignora d'ora in poi</button>
        </form>
      </div>
    </div>

    <?php if ($cooc): ?>
    <div class="card">
      <b>Compare spesso con</b>
      <div class="row" style="margin-top:8px">
        <?php foreach ($cooc as $c): ?>
          <a class="badge" href="tags.php?tag=<?=urlencode((string)$c['name'])?>"><?=h((string)$c['name'])?> <span class="meta">(<?= (int)$c['c'] ?>)</span></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <b>Voci con questo tag</b>
      <hr>
      <?php foreach ($entries as $x): ?>
        <div class="feed-entry">
          <a href="<?=h(entry_url($x))?>"><b><?=h((string)($x['title'] ?: first_line((string)$x['body'], 80)))?></b></a>
          <span class="badge"><?=h((string)$x['source'])?></span>
          <div class="meta"><?=h(fmt_dt((string)$x['created_at']))?> · <?=h((string)$x['slug'])?></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$entries): ?><div class="meta">Nessuna voce.</div><?php endif; ?>
    </div>
  </div>
  <?php
  exit;
}

/* ===================== elenco completo ===================== */
$sort = (string)($_GET['sort'] ?? 'count');
$order = $sort === 'name' ? 't.name COLLATE NOCASE ASC' : 'c DESC, t.name COLLATE NOCASE ASC';

$tags = [];
$r = $db->query("
  SELECT t.id, t.name, t.kind,
         COUNT(DISTINCT et.entry_id) AS c,
         MAX(e.created_at) AS last_used
  FROM tags t
  LEFT JOIN entry_tags et ON et.tag_id = t.id
  LEFT JOIN entries e ON e.id = et.entry_id
  GROUP BY t.id
  ORDER BY $order
");
while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $tags[] = $x;

$sw = [];
$r = $db->query('SELECT word, added_at FROM stopwords_custom ORDER BY word');
while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $sw[] = $x;

$n_manual = count(array_filter($tags, fn($t) => $t['kind'] === 'manual'));
$n_auto = count($tags) - $n_manual;
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<meta name="theme-color" content="#2f6feb">
<link rel="manifest" href="manifest.php">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script src="assets/pwa.js" defer></script>
<title><?=h($site)?> — Tag</title>

<?php render_header('Tag', 'tags'); ?>

<div class="wrap">
  <?php if ($flash): ?><div class="card"><b><?= $flash[0] === 'ok' ? 'OK:' : 'Errore:' ?></b> <?=h((string)$flash[1])?></div><?php endif; ?>

  <div class="card">
    <div class="row" style="justify-content:space-between">
      <b><?=count($tags)?> tag <span class="meta">(<?=$n_manual?> manuali · <?=$n_auto?> auto)</span></b>
      <span class="meta">ordina:
        <a href="tags.php?sort=count"<?= $sort !== 'name' ? ' style="font-weight:bold"' : '' ?>>frequenza</a> ·
        <a href="tags.php?sort=name"<?= $sort === 'name' ? ' style="font-weight:bold"' : '' ?>>nome</a>
      </span>
    </div>
    <hr>
    <?php foreach ($tags as $t): ?>
      <div class="feed-entry">
        <div class="row" style="justify-content:space-between">
          <div>
            <a href="tags.php?tag=<?=urlencode((string)$t['name'])?>"><b><?=h((string)$t['name'])?></b></a>
            <span class="badge <?= $t['kind'] === 'manual' ? 'red-stamp' : '' ?>"><?=h((string)$t['kind'])?></span>
            <span class="meta"><?= (int)$t['c'] ?> voci<?php if ($t['last_used']): ?> · ultima <?=h(fmt_dt((string)$t['last_used'], false))?><?php endif; ?></span>
          </div>
          <div class="btns">
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="action" value="kind">
              <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <input type="hidden" name="to" value="<?= $t['kind'] === 'auto' ? 'manual' : 'auto' ?>">
              <input type="hidden" name="ret" value="tags.php?sort=<?=h($sort)?>">
              <button class="btn" type="submit"><?= $t['kind'] === 'auto' ? '→ manuale' : '→ auto' ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Eliminare «<?=h((string)$t['name'])?>» e ignorarlo d\'ora in poi?')">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="action" value="ignore">
              <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <input type="hidden" name="ret" value="tags.php?sort=<?=h($sort)?>">
              <button class="btn" type="submit">ignora</button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$tags): ?><div class="meta">Nessun tag ancora.</div><?php endif; ?>
  </div>

  <div class="card">
    <b>Stopword personalizzate</b>
    <div class="meta" style="margin-top:4px">
      Escluse dall'estrazione keyword e dagli auto-tag. Effetto sulle voci esistenti
      solo dopo un ricalcolo.
    </div>
    <form method="post" class="row" style="margin-top:8px; gap:6px">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="sw_add">
      <input type="hidden" name="ret" value="tags.php">
      <input class="grow" name="word" placeholder="parola da ignorare" required>
      <button class="btn" type="submit">Aggiungi</button>
    </form>
    <?php if ($sw): ?>
      <div class="row" style="margin-top:10px">
        <?php foreach ($sw as $w): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="sw_del">
            <input type="hidden" name="word" value="<?=h((string)$w['word'])?>">
            <input type="hidden" name="ret" value="tags.php">
            <button class="badge" type="submit" title="rimuovi"><?=h((string)$w['word'])?> ✕</button>
          </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <form method="post" style="margin-top:12px" onsubmit="return confirm('Ricalcolare keyword, tag e archi di tutte le voci?')">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="rebuild">
      <input type="hidden" name="ret" value="tags.php">
      <button class="btn" type="submit">Ricalcola tutto ora</button>
    </form>
  </div>
</div>
