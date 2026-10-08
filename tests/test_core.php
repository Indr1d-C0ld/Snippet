<?php
declare(strict_types=1);

/* Fondamenta: migrazioni, direttive, ciclo di vita delle voci. */

group('core: migrazioni', function () {
  $db = fresh_db();
  $v = (int)$db->querySingle('PRAGMA user_version');
  $all = require dirname(__DIR__) . (is_file(dirname(__DIR__) . '/webapp/migrations.php') ? '/webapp' : '') . '/migrations.php';
  eq($v, max(array_keys($all)), 'DB nuovo portato all\'ultima versione');
  foreach (['kv', 'entry_terms', 'persons', 'entry_persons', 'clusters', 'entry_vectors',
            'link_previews', 'auth_tokens', 'login_throttle'] as $t) {
    check((int)$db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name='$t'") === 1, "tabella $t presente");
  }
  eq(db_migrate($db), $v, 'seconda esecuzione: nessun passo, stessa versione');

  // DB "storico" (user_version 0, senza login_throttle): migra senza errori
  $db->close();
  $p = cfg()['db_path'];
  foreach ([$p, "$p-wal", "$p-shm"] as $f) if (is_file($f)) unlink($f);
  $db = new SQLite3($p); $db->enableExceptions(true);
  $db->exec((string)file_get_contents(schema_path()));
  $db->exec('DROP TABLE login_throttle; PRAGMA user_version = 0;');
  $db->exec("INSERT INTO entries(slug, body, raw, author) VALUES('2026-09-01-1', 'vecchia', 'vecchia', 'admin')");
  eq(db_migrate($db), $v, 'DB storico migrato fino all\'ultima versione');
  eq((int)$db->querySingle('SELECT COUNT(*) FROM entries'), 1, 'i dati esistenti restano');
  check((int)$db->querySingle("SELECT COUNT(*) FROM pragma_table_info('entries') WHERE name='cluster'") === 1,
        'colonna entries.cluster aggiunta');
});

group('core: kv', function () {
  $db = fresh_db();
  eq(kv_get($db, 'x', 'def'), 'def', 'chiave assente → default');
  kv_set($db, 'x', '1'); kv_set($db, 'x', '2');
  eq(kv_get($db, 'x'), '2', 'set/overwrite');
  kv_set($db, 'x', null);
  eq(kv_get($db, 'x'), null, 'set null → cancella');
});

group('core: direttive', function () {
  $d = nlp_parse_directives("Titolo breve :: corpo del testo #Viaggio\n!pin\n!tag: studio, Lavoro\n!data:03/09/2026 10:30\n[[7]] e [[04/09/2026-8]]");
  eq($d['title'], 'Titolo breve', 'titolo con ::');
  eq($d['pinned'], true, '!pin');
  eq($d['created_at'], '2026-09-03 08:30:00', '!data ora di Roma → UTC');
  $t = $d['tags']; sort($t);
  eq($t, ['lavoro', 'studio', 'viaggio'], 'tag da !tag e #hashtag');
  eq($d['mentions'], ['7', '04/09/2026-8'], 'menzioni [[..]]');
  eq(nlp_parse_directives("Riga titolo\n\nCorpo")['title'], 'Riga titolo', 'titolo da riga breve + vuota');
  eq(nlp_parse_directives("vedi https://x.it/a::b qui")['title'], null, ':: senza spazi non spezza gli URL');
});

group('core: ciclo di vita voce', function () {
  $db = fresh_db();
  $a = save($db, "Prima :: testo sul mare e sulla barca #mare");
  $b = save($db, "Seconda :: ancora mare, barca e vento [[$a]] #mare");
  $slug = (string)$db->querySingle("SELECT slug FROM entries WHERE id=$a");
  check((bool)preg_match('/^\d{4}-\d{2}-\d{2}-' . $a . '$/', $slug), 'slug AAAA-MM-GG-id');
  eq(entry_resolve_ref($db, (string)$a), $a, 'risolve id');
  eq(entry_resolve_ref($db, $slug), $a, 'risolve slug ISO');
  [$y, $m, $dd] = explode('-', substr($slug, 0, 10));
  eq(entry_resolve_ref($db, "$dd/$m/$y-$a"), $a, 'risolve slug italiano');
  eq((int)$db->querySingle("SELECT COUNT(*) FROM links WHERE src_id=$b AND dst_id=$a AND kind='manual'"), 1, 'backlink manuale');

  $db->exec("UPDATE entries SET pinned=1 WHERE id=$a");
  entry_update($db, $a, "Prima :: testo modificato sul mare #mare", 'admin');
  eq((int)$db->querySingle("SELECT pinned FROM entries WHERE id=$a"), 1, 'il pin sopravvive a una modifica');

  $db->exec("INSERT INTO ingest_log(raw_json, entry_id, status) VALUES('{\"text\":\"segreto\"}', $b, 'ok')");
  mkdir(cfg()['attachments_dir'] . "/$b", 0775, true);
  file_put_contents(cfg()['attachments_dir'] . "/$b/f.txt", 'x');
  $db->exec("INSERT INTO attachments(entry_id, kind, path) VALUES($b, 'document', '$b/f.txt')");
  $r = entry_delete($db, $b);
  eq($r['files'], 1, 'eliminazione: file allegato rimosso');
  check(!is_dir(cfg()['attachments_dir'] . "/$b"), 'eliminazione: cartella allegati rimossa');
  eq($db->querySingle("SELECT raw_json FROM ingest_log WHERE entry_id=$b"), null, 'eliminazione: testo grezzo purgato dal log');
});
