<?php
declare(strict_types=1);

/**
 * Header + navigazione condivisi. Richiede lib.php gia' incluso.
 *   render_header('Scrivi', 'compose');
 */
function render_header(string $title, string $active = ''): void {
  $u = auth_user();
  $site = (string)(cfg()['site_name'] ?? 'snippet');

  $links = [
    'compose' => ['compose.php', '✍ Scrivi'],
    'diary'   => ['diary.php',   '📓 Diario'],
    'search'  => ['search.php',  'Cerca'],
    'tags'    => ['tags.php',    'Tag'],
    'people'  => ['people.php',  '👤 Persone'],
    'themes'  => ['themes.php',  'Temi'],
    'map'     => ['map.php',     '🕸 Mappa'],
    'stats'   => ['stats.php',   'Statistiche'],
  ];
  ?>
  <header>
    <b><?=h($title)?></b>
    <div class="meta">
      <?php if ($u): ?>
        <span class="meta"><?=h($site)?> · <?=h((string)$u['username'])?></span>
        <?php foreach ($links as $key => [$href, $label]): ?>
          · <a href="<?=h($href)?>"<?= $key === $active ? ' style="font-weight:bold"' : '' ?>><?=h($label)?></a>
        <?php endforeach; ?>
        · <a href="export.php"<?= $active === 'export' ? ' style="font-weight:bold"' : '' ?>>Esporta</a>
        · <a href="profile.php"<?= $active === 'profile' ? ' style="font-weight:bold"' : '' ?>>Profilo</a>
        · <a href="logout.php">Esci</a>
      <?php else: ?>
        <span class="meta"><?=h($site)?></span> · <a href="login.php">Accedi</a>
      <?php endif; ?>
    </div>
  </header>
  <?php
}
