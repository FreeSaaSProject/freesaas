<?php
declare(strict_types=1);
/** @var string $panelHtml */
/** @var string $message */
/** @var bool $canEdit */
?>
<section class="page-head"><h1><?= te('laundry.menu.home') ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>

<?= $panelHtml ?>

<section style="margin-top:1.5rem">
  <h2 style="font-size:1rem"><?= te('laundry.home.reference') ?></h2>
  <ul class="muted" style="list-style:none;padding:0;display:grid;gap:0.4rem">
    <li><a href="/laundry/shipments"><?= te('laundry.menu.shipments') ?></a> — <?= te('laundry.home.ref_shipments') ?></li>
    <li><a href="/laundry/moves"><?= te('laundry.menu.moves') ?></a> — <?= te('laundry.home.ref_moves') ?></li>
    <?php if ($canEdit): ?>
      <li><a href="/laundry/inventory"><?= te('laundry.menu.inventory') ?></a> — <?= te('laundry.home.ref_inventory') ?></li>
      <li><a href="/laundry/settings"><?= te('laundry.menu.settings') ?></a> — <?= te('laundry.home.ref_settings') ?></li>
    <?php endif; ?>
  </ul>
</section>
