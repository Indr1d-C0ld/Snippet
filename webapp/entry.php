<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/lib_nlp.php';
require __DIR__ . '/nav.php';
require_login();

$site = (string)(cfg()['site_name'] ?? 'snippet');

/* -------- risoluzione voce -------- */
$ref = trim((string)($_GET['e'] ?? ''));
if ($ref === '') { http_response_code(400); die('Parametro e mancante.'); }

$db = db_ro();
$eid = entry_resolve_ref($db, $ref);
if ($eid === null) { http_response_code(404); die('Voce non trovata: ' . h($ref)); }

/* -------- azioni POST (pin / archivia / elimina) -------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check()) { http_response_code(403); die('CSRF non valido'); }
  $action = (string)($_POST['action'] ?? '');
  $dbw = db_rw();
  if ($action === 'pin' || $action === 'unpin') {
    $dbw->exec('UPDATE entries SET pinned=' . ($action === 'pin' ? 1 : 0) . ' WHERE id=' . $eid);
    flash_set('ok', $action === 'pin' ? 'Voce fissata.' : 'Voce non piu\' fissata.');
    header('Location: ' . entry_url($ref)); exit;
  }
  if ($action === 'archive' || $action === 'unarchive') {
    $dbw->exec('UPDATE entries SET archived=' . ($action === 'archive' ? 1 : 0) . ' WHERE id=' . $eid);
    flash_set('ok', $action === 'archive' ? 'Voce archiviata.' : 'Voce ripristinata.');
    header('Location: ' . entry_url($ref)); exit;
  }
  if ($action === 'delete') {
    $dbw->exec('DELETE FROM entries WHERE id=' . $eid);
    flash_set('ok', 'Voce eliminata.');
    header('Location: diary.php'); exit;
  }
  http_response_code(400); die('Azione non valida.');
}

/* -------- caricamento -------- */
$e = $db->querySingle('SELECT * FROM entries WHERE id=' . $eid, true);
if (!$e) { http_response_code(404); die('Voce non trovata.'); }

$flash = flash_take();

$keywords = [];
$rs = $db->query('SELECT term, freq FROM entry_keywords WHERE entry_id=' . $eid . ' ORDER BY rank ASC');
while ($rs && ($r = $rs->fetchArray(SQLITE3_ASSOC))) $keywords[] = $r;

$tags_manual = $tags_auto = [];
$rs = $db->query(
  'SELECT t.name, et.auto FROM entry_tags et JOIN tags t ON t.id = et.tag_id
   WHERE et.entry_id=' . $eid . ' ORDER BY et.auto ASC, t.name ASC'
);
while ($rs && ($r = $rs->fetchArray(SQLITE3_ASSOC))) {
  if ((int)$r['auto'] === 1) $tags_auto[] = (string)$r['name'];
  else $tags_manual[] = (string)$r['name'];
}

/* correlati: archi in entrambe le direzioni, deduplicati sull'altra voce */
$related = []; // other_id => ['kind'=>, 'score'=>, row...]
$st = $db->prepare("
  SELECT CASE WHEN l.src_id = :id THEN l.dst_id ELSE l.src_id END AS other,
         l.kind, l.score
  FROM links l
  WHERE (l.src_id = :id OR l.dst_id = :id) AND l.kind <> 'manual'
");
$st->bindValue(':id', $eid, SQLITE3_INTEGER);
$r = $st->execute();
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
  $o = (int)$row['other'];
  if (!isset($related[$o]) || $row['score'] > $related[$o]['score']) {
    $related[$o] = ['kind' => (string)$row['kind'], 'score' => (float)$row['score']];
  }
}

/* backlink manuali: chi menziona questa voce / chi e' menzionato da questa */
$mentions_out = $mentions_in = [];
$st = $db->prepare("SELECT dst_id FROM links WHERE src_id = :id AND kind = 'manual'");
$st->bindValue(':id', $eid, SQLITE3_INTEGER);
$r = $st->execute();
while ($row = $r->fetchArray(SQLITE3_ASSOC)) $mentions_out[] = (int)$row['dst_id'];
$st = $db->prepare("SELECT src_id FROM links WHERE dst_id = :id AND kind = 'manual'");
$st->bindValue(':id', $eid, SQLITE3_INTEGER);
$r = $st->execute();
while ($row = $r->fetchArray(SQLITE3_ASSOC)) $mentions_in[] = (int)$row['src_id'];

/* riepilogo (titolo/slug/data) per un insieme di id */
function entries_brief(SQLite3 $db, array $ids): array {
  $ids = array_values(array_unique(array_map('intval', $ids)));
  if (!$ids) return [];
  $in = implode(',', array_fill(0, count($ids), '?'));
  $st = $db->prepare("SELECT id, slug, title, body, created_at FROM entries WHERE id IN ($in)");
  $i = 1;
  foreach ($ids as $x) $st->bindValue($i++, $x, SQLITE3_INTEGER);
  $out = [];
  $r = $st->execute();
  while ($row = $r->fetchArray(SQLITE3_ASSOC)) $out[(int)$row['id']] = $row;
  return $out;
}
$brief = entries_brief($db, array_merge(array_keys($related), $mentions_out, $mentions_in));

$attachments = [];
$rs = $db->query('SELECT id, kind, orig_name, mime, bytes FROM attachments WHERE entry_id=' . $eid . ' ORDER BY id ASC');
while ($rs && ($r = $rs->fetchArray(SQLITE3_ASSOC))) $attachments[] = $r;

$notes = [];
$rs = $db->query('SELECT id, note, author, created_at, updated_at FROM notes WHERE entry_id=' . $eid . ' ORDER BY created_at DESC');
while ($rs && ($r = $rs->fetchArray(SQLITE3_ASSOC))) $notes[] = $r;

$disp_title = (string)($e['title'] ?: first_line((string)$e['body'], 90) ?: ('Voce ' . $eid));

function brief_label(?array $b): string {
  if (!$b) return '(voce assente)';
  $t = (string)($b['title'] ?: first_line((string)$b['body'], 70));
  return $t !== '' ? $t : ('voce ' . (string)$b['id']);
}
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<meta name="theme-color" content="#2f6feb">
<link rel="manifest" href="manifest.php">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script src="assets/pwa.js" defer></script>
<title><?=h($site)?> — <?=h($disp_title)?></title>

<?php render_header($disp_title, ''); ?>

<div class="wrap grid">
  <div>
    <div class="card">
      <div class="row" style="justify-content:space-between">
        <b><?=h($disp_title)?></b>
        <span class="badge"><?=h((string)$e['slug'])?></span>
      </div>
      <div class="meta" style="margin-top:6px">
        <?=h(fmt_dt((string)$e['created_at']))?>
        <?php if (!empty($e['updated_at'])): ?> · modificata <?=h(fmt_dt((string)$e['updated_at']))?><?php endif; ?>
        · fonte <?=h((string)$e['source'])?>
        <?php if (!empty($e['lang'])): ?> · <?=h((string)$e['lang'])?><?php endif; ?>
        · <?= (int)$e['word_count'] ?> parole
        <?php if ((int)$e['pinned'] === 1): ?> · 📌 fissata<?php endif; ?>
        <?php if ((int)$e['archived'] === 1): ?> · 🗄 archiviata<?php endif; ?>
      </div>

      <?php if ($flash): ?>
        <div class="meta" style="margin-top:8px"><b><?= $flash[0] === 'ok' ? 'OK:' : 'Errore:' ?></b> <?=h((string)$flash[1])?></div>
      <?php endif; ?>

      <hr>
      <div class="entry-body"><?= entry_render_body((string)$e['body']) ?></div>

      <hr>
      <div class="btns">
        <a class="btn" href="edit.php?e=<?=rawurlencode((string)$e['slug'])?>">Modifica</a>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="<?= (int)$e['pinned'] === 1 ? 'unpin' : 'pin' ?>">
          <button class="btn" type="submit"><?= (int)$e['pinned'] === 1 ? 'Togli 📌' : '📌 Fissa' ?></button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="<?= (int)$e['archived'] === 1 ? 'unarchive' : 'archive' ?>">
          <button class="btn" type="submit"><?= (int)$e['archived'] === 1 ? 'Ripristina' : 'Archivia' ?></button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('Eliminare definitivamente questa voce?')">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="delete">
          <button class="btn" type="submit">Elimina</button>
        </form>
      </div>
    </div>

    <div class="card">
      <b>Note (<?=count($notes)?>)</b>
      <form id="noteForm" style="margin-top:8px">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="entry_id" value="<?=$eid?>">
        <textarea name="note" placeholder="Nota su questa voce…" required></textarea>
        <button class="btn" type="submit">Aggiungi nota</button>
        <span id="noteMsg" class="meta"></span>
      </form>

      <?php foreach ($notes as $n): ?>
        <div class="card" style="margin-top:10px">
          <div class="meta"><b><?=h((string)$n['author'])?></b> · <?=h(fmt_dt((string)$n['created_at']))?>
            <?php if (!empty($n['updated_at'])): ?> · agg. <?=h(fmt_dt((string)$n['updated_at']))?><?php endif; ?></div>
          <div style="margin-top:6px"><?= nl2br(h((string)$n['note'])) ?></div>
          <div style="margin-top:6px">
            <button class="btn" type="button" onclick="delNote(<?= (int)$n['id'] ?>)">Elimina</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div>
    <div class="card">
      <b>Parole piu' frequenti</b>
      <?php if (!$keywords): ?>
        <div class="meta" style="margin-top:6px">Nessuna (testo troppo breve).</div>
      <?php else: ?>
        <div class="row" style="margin-top:8px">
          <?php foreach ($keywords as $k): ?>
            <a class="badge" href="search.php?q=<?=urlencode((string)$k['term'])?>"><?=h((string)$k['term'])?> <span class="meta">(<?= (int)$k['freq'] ?>)</span></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <hr>
      <b>Tag</b>
      <?php if (!$tags_manual && !$tags_auto): ?>
        <div class="meta" style="margin-top:6px">Nessun tag.</div>
      <?php else: ?>
        <div class="row" style="margin-top:8px">
          <?php foreach ($tags_manual as $t): ?>
            <a class="badge red-stamp" href="tags.php?tag=<?=urlencode($t)?>"><?=h($t)?></a>
          <?php endforeach; ?>
          <?php foreach ($tags_auto as $t): ?>
            <a class="badge" href="tags.php?tag=<?=urlencode($t)?>" title="tag automatico"><?=h($t)?> <span class="meta">·auto</span></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <b>Correlati</b>
      <?php if (!$related): ?>
        <div class="meta" style="margin-top:6px">Nessuna correlazione automatica.</div>
      <?php else: ?>
        <?php
          uasort($related, static fn($a, $b) => $b['score'] <=> $a['score']);
        ?>
        <ul class="small" style="margin-top:8px">
          <?php foreach ($related as $oid => $meta): $b = $brief[$oid] ?? null; ?>
            <li>
              <a href="entry.php?e=<?=rawurlencode((string)($b['slug'] ?? $oid))?>"><?=h(brief_label($b))?></a>
              <span class="badge"><?=h($meta['kind'])?> · <?= (int)round($meta['score']) ?></span>
              <?php if ($b): ?><div class="meta"><?=h(fmt_dt((string)$b['created_at']))?></div><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <?php if ($mentions_out || $mentions_in): ?>
    <div class="card">
      <b>Collegamenti espliciti</b>
      <?php if ($mentions_out): ?>
        <div class="meta" style="margin-top:8px">Questa voce menziona:</div>
        <ul class="small">
          <?php foreach ($mentions_out as $oid): $b = $brief[$oid] ?? null; ?>
            <li><a href="entry.php?e=<?=rawurlencode((string)($b['slug'] ?? $oid))?>"><?=h(brief_label($b))?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($mentions_in): ?>
        <div class="meta" style="margin-top:8px">Menzionata in:</div>
        <ul class="small">
          <?php foreach ($mentions_in as $oid): $b = $brief[$oid] ?? null; ?>
            <li><a href="entry.php?e=<?=rawurlencode((string)($b['slug'] ?? $oid))?>"><?=h(brief_label($b))?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($attachments): ?>
    <div class="card">
      <b>Allegati</b>
      <ul class="small" style="margin-top:8px">
        <?php foreach ($attachments as $a): ?>
          <li>
            <a href="attachment.php?id=<?= (int)$a['id'] ?>"><?=h((string)($a['orig_name'] ?: $a['kind']))?></a>
            <span class="meta"><?=h((string)$a['kind'])?><?php if (!empty($a['bytes'])): ?> · <?= (int)round($a['bytes']/1024) ?> KB<?php endif; ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
const nf = document.getElementById('noteForm');
const nm = document.getElementById('noteMsg');
nf.addEventListener('submit', async (ev) => {
  ev.preventDefault();
  nm.textContent = 'Salvataggio…';
  const r = await fetch('notes.php', { method: 'POST', body: new FormData(nf) });
  const j = await r.json().catch(() => null);
  if (!j || !j.ok) { nm.textContent = 'Errore: ' + (j?.error || 'imprevisto'); return; }
  location.reload();
});
async function delNote(id) {
  if (!confirm('Eliminare la nota #' + id + '?')) return;
  const fd = new FormData();
  fd.set('csrf', '<?=h(csrf_token())?>');
  fd.set('action', 'delete');
  fd.set('id', String(id));
  const r = await fetch('notes.php', { method: 'POST', body: fd });
  const j = await r.json().catch(() => null);
  if (!j || !j.ok) { alert('Errore: ' + (j?.error || 'imprevisto')); return; }
  location.reload();
}
</script>
