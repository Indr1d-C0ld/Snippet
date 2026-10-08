<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/lib_nlp.php';
require __DIR__ . '/nav.php';
require_login();

/**
 * Persone: chi compare nel diario. Una persona confermata viene riconosciuta
 * in tutte le voci (nome o alias, anche come #hashtag), non diventa mai un
 * auto-tag e conta nelle correlazioni (voci che parlano della stessa persona).
 */

$site = (string)(cfg()['site_name'] ?? 'snippet');

/* ===================== POST ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check()) { http_response_code(403); die('CSRF non valido'); }
  $action = (string)($_POST['action'] ?? '');
  $back = 'people.php';
  try {
    $db = db_rw();
    $name = trim((string)($_POST['name'] ?? ''));
    $reindex = false;
    if ($action === 'add') {
      $id = person_add($db, $name, (string)($_POST['aliases'] ?? ''));
      $reindex = true;
      flash_set('ok', '«' . $name . '» aggiunto alle persone.');
      $back = 'people.php?id=' . $id;
    } elseif ($action === 'ignore') {
      person_ignore($db, $name);
      flash_set('ok', '«' . $name . '» non verra\' piu\' proposto.');
    } elseif ($action === 'ignore_all') {
      $n = 0;
      foreach ((array)($_POST['names'] ?? []) as $nm) { person_ignore($db, (string)$nm); $n++; }
      flash_set('ok', "$n nomi scartati.");
    } elseif ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      if ($name === '') throw new RuntimeException('Il nome non puo\' essere vuoto.');
      $aliases = implode(', ', array_filter(array_map('trim', explode(',', (string)($_POST['aliases'] ?? '')))));
      $st = $db->prepare('UPDATE persons SET name=:n, aliases=:a, note=:o WHERE id=:i');
      $st->bindValue(':n', $name, SQLITE3_TEXT);
      $st->bindValue(':a', $aliases, SQLITE3_TEXT);
      $st->bindValue(':o', trim((string)($_POST['note'] ?? '')) ?: null, SQLITE3_TEXT);
      $st->bindValue(':i', $id, SQLITE3_INTEGER);
      $st->execute();
      $reindex = true;
      flash_set('ok', 'Persona aggiornata.');
      $back = 'people.php?id=' . $id;
    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      $n = (string)$db->querySingle('SELECT name FROM persons WHERE id=' . $id);
      $db->exec('DELETE FROM persons WHERE id=' . $id);
      if ($n !== '') person_ignore($db, $n);
      $reindex = true;
      flash_set('ok', '«' . $n . '» rimosso dalle persone (le voci restano).');
    }
    if ($reindex) {
      persons_reindex($db);
      graph_rebuild($db);
    }
  } catch (Throwable $e) {
    flash_set('err', $e->getMessage());
  }
  header('Location: ' . $back);
  exit;
}

$flash = flash_take();
$db = db_ro();
$pid = (int)($_GET['id'] ?? 0);

function people_head(string $site, string $title): void { ?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<meta name="theme-color" content="#2f6feb">
<link rel="manifest" href="manifest.php">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script src="assets/pwa.js" defer></script>
<title><?=h($site)?> — <?=h($title)?></title>
<?php render_header($title, 'people');
}

/* ===================== dettaglio persona ===================== */
if ($pid > 0) {
  $p = $db->querySingle('SELECT * FROM persons WHERE id=' . $pid, true);
  if (!$p) { http_response_code(404); die('Persona non trovata.'); }
  $entries = [];
  $r = $db->query("SELECT e.id, e.slug, e.title, e.body, e.created_at, ep.mentions
                   FROM entry_persons ep JOIN entries e ON e.id = ep.entry_id
                   WHERE ep.person_id = $pid ORDER BY e.created_at DESC");
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $entries[] = $x;
  $with = [];
  $r = $db->query("SELECT p2.id, p2.name, COUNT(*) c FROM entry_persons a
                   JOIN entry_persons b ON b.entry_id = a.entry_id AND b.person_id <> a.person_id
                   JOIN persons p2 ON p2.id = b.person_id
                   WHERE a.person_id = $pid GROUP BY p2.id ORDER BY c DESC LIMIT 10");
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $with[] = $x;
  $tags = [];
  $r = $db->query("SELECT t.name, COUNT(*) c FROM entry_persons ep
                   JOIN entry_tags et ON et.entry_id = ep.entry_id JOIN tags t ON t.id = et.tag_id
                   WHERE ep.person_id = $pid GROUP BY t.id ORDER BY c DESC, t.name LIMIT 15");
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $tags[] = $x;

  people_head($site, '👤 ' . (string)$p['name']);
  ?>
  <div class="wrap grid">
    <div>
      <?php if ($flash): ?><div class="card"><b><?= $flash[0] === 'ok' ? 'OK:' : 'Errore:' ?></b> <?=h((string)$flash[1])?></div><?php endif; ?>
      <div class="card">
        <div class="row" style="justify-content:space-between">
          <b>👤 <?=h((string)$p['name'])?></b>
          <a class="btn" href="people.php">← tutte le persone</a>
        </div>
        <div class="meta" style="margin-top:6px">
          <?=count($entries)?> voci
          <?php if ($entries): ?> · dal <?=h(fmt_dt((string)end($entries)['created_at'], false))?> al <?=h(fmt_dt((string)$entries[0]['created_at'], false))?><?php endif; ?>
          <?php if (trim((string)$p['aliases']) !== ''): ?> · alias: <?=h((string)$p['aliases'])?><?php endif; ?>
        </div>
        <?php if (!empty($p['note'])): ?><div class="quote"><?=nl2br(h((string)$p['note']))?></div><?php endif; ?>
      </div>

      <div class="card">
        <b>Cronologia</b>
        <?php if (!$entries): ?><div class="meta" style="margin-top:6px">Nessuna voce la menziona.</div><?php endif; ?>
        <?php foreach ($entries as $x): ?>
          <div class="feed-entry">
            <a href="<?=h(entry_url($x))?>"><b><?=h((string)($x['title'] ?: first_line((string)$x['body'], 80)))?></b></a>
            <div class="meta"><?=h(fmt_dt((string)$x['created_at']))?> · <?= (int)$x['mentions'] ?> menzioni</div>
            <div class="small" style="margin-top:4px"><?=h(mb_substr(trim(preg_replace('/\s+/', ' ', (string)$x['body']) ?? ''), 0, 220, 'UTF-8'))?>…</div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div>
      <?php if ($with || $tags): ?>
      <div class="card">
        <?php if ($with): ?>
          <b>Compare insieme a</b>
          <div class="row" style="margin-top:8px">
            <?php foreach ($with as $w): ?><a class="badge" href="people.php?id=<?= (int)$w['id'] ?>">👤 <?=h((string)$w['name'])?> · <?= (int)$w['c'] ?></a><?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($tags): ?>
          <?php if ($with): ?><hr><?php endif; ?>
          <b>Argomenti</b>
          <div class="row" style="margin-top:8px">
            <?php foreach ($tags as $t): ?><a class="badge" href="tags.php?tag=<?=urlencode((string)$t['name'])?>"><?=h((string)$t['name'])?> · <?= (int)$t['c'] ?></a><?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="card">
        <b>Modifica</b>
        <form method="post" style="margin-top:8px">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?=$pid?>">
          <div class="meta">Nome</div>
          <input name="name" value="<?=h((string)$p['name'])?>" required>
          <div class="meta" style="margin-top:8px">Alias (separati da virgola: soprannomi, solo cognome…)</div>
          <input name="aliases" value="<?=h((string)$p['aliases'])?>" placeholder="es. Scarpati, Mario S.">
          <div class="meta" style="margin-top:8px">Nota</div>
          <textarea name="note" style="min-height:70px"><?=h((string)($p['note'] ?? ''))?></textarea>
          <button class="btn" type="submit" style="margin-top:8px">Salva</button>
        </form>
        <hr>
        <form method="post" onsubmit="return confirm('Rimuovere questa persona? Le voci restano; il nome non verra\' piu\' proposto.')">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?=$pid?>">
          <button class="btn" type="submit">Rimuovi persona</button>
        </form>
      </div>
    </div>
  </div>
  <?php
  exit;
}

/* ===================== elenco ===================== */
$people = [];
$r = $db->query("SELECT p.id, p.name, p.aliases, COUNT(ep.entry_id) n, MAX(e.created_at) last
                 FROM persons p LEFT JOIN entry_persons ep ON ep.person_id = p.id
                 LEFT JOIN entries e ON e.id = ep.entry_id
                 GROUP BY p.id ORDER BY n DESC, p.name COLLATE NOCASE");
while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $people[] = $x;

// candidati su tutto il diario: prima le persone probabili, poi gli altri nomi propri
$persons = nlp_persons($db);
$cand = [];
$r = $db->query('SELECT id, title, body FROM entries');
while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) {
  foreach (nlp_person_candidates($db, (string)$x['title'] . "\n" . (string)$x['body'], $persons, 12) as $c) {
    $cand[$c] = ($cand[$c] ?? 0) + 1;
  }
}
$likely = $other = [];
foreach ($cand as $c => $n) {
  if (nlp_is_likely_person((string)$c)) $likely[(string)$c] = $n; else $other[(string)$c] = $n;
}
arsort($likely); arsort($other);

people_head($site, 'Persone');
?>
<div class="wrap grid">
  <div>
    <?php if ($flash): ?><div class="card"><b><?= $flash[0] === 'ok' ? 'OK:' : 'Errore:' ?></b> <?=h((string)$flash[1])?></div><?php endif; ?>
    <div class="card">
      <b>Persone (<?=count($people)?>)</b>
      <?php if (!$people): ?>
        <div class="meta" style="margin-top:6px">Nessuna ancora. Conferma i nomi proposti qui a fianco, o aggiungine una.</div>
      <?php endif; ?>
      <?php foreach ($people as $p): ?>
        <div class="feed-entry">
          <a href="people.php?id=<?= (int)$p['id'] ?>"><b>👤 <?=h((string)$p['name'])?></b></a>
          <span class="meta"> · <?= (int)$p['n'] ?> voci<?php if ($p['last']): ?> · ultima <?=h(fmt_dt((string)$p['last'], false))?><?php endif; ?>
            <?php if (trim((string)$p['aliases']) !== ''): ?> · alias: <?=h((string)$p['aliases'])?><?php endif; ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <b>Aggiungi una persona</b>
      <form method="post" style="margin-top:8px">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="add">
        <input name="name" placeholder="Nome (es. Mario Scarpati)" required>
        <input name="aliases" placeholder="Alias, separati da virgola (facoltativo)" style="margin-top:6px">
        <button class="btn" type="submit" style="margin-top:8px">Aggiungi</button>
      </form>
    </div>
  </div>

  <div>
    <div class="card">
      <b>Da confermare</b>
      <div class="meta" style="margin-top:4px">Nomi propri trovati nel diario. Confermando, la persona viene riconosciuta in tutte le voci.</div>
      <?php if (!$likely && !$other): ?><div class="meta" style="margin-top:8px">Niente da confermare.</div><?php endif; ?>
      <?php foreach ([['Probabilmente persone', $likely], ['Altri nomi propri', $other]] as [$label, $list]): if (!$list) continue; ?>
        <div class="meta" style="margin-top:12px"><b><?=h($label)?></b></div>
        <?php foreach ($list as $c => $n): ?>
          <div class="row" style="margin-top:6px; gap:6px">
            <span class="small grow"><?=h((string)$c)?> <span class="meta">· <?= (int)$n ?> voci</span></span>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="name" value="<?=h((string)$c)?>">
              <button class="badge" type="submit">👤 sì</button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="action" value="ignore">
              <input type="hidden" name="name" value="<?=h((string)$c)?>">
              <button class="badge" type="submit">✕ no</button>
            </form>
          </div>
        <?php endforeach; ?>
        <?php if ($label === 'Altri nomi propri' && count($list) > 1): ?>
          <form method="post" style="margin-top:10px" onsubmit="return confirm('Scartare tutti gli altri nomi propri?')">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="ignore_all">
            <?php foreach (array_keys($list) as $c): ?><input type="hidden" name="names[]" value="<?=h((string)$c)?>"><?php endforeach; ?>
            <button class="btn" type="submit">✕ Nessuno di questi è una persona</button>
          </form>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</div>
