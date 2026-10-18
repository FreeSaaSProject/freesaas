<?php
declare(strict_types=1);
/** @var array<int,string> $accounts */
/** @var list<array<string,mixed>> $types */
/** @var array<int,array<int,int>> $matrix */
/** @var bool $started */
/** @var list<array<string,mixed>> $open */
/** @var bool $canShip */
/** @var bool $canEdit */
$typeNames = [];
foreach ($types as $t) {
    $typeNames[(int)$t['id']] = (string)$t['name'];
}
?>
<section class="page-head">
  <div class="page-head-row">
    <h1><?= te('laundry.menu.home') ?></h1>
    <div class="page-actions">
      <?php if ($canShip): ?>
        <a class="btn-primary" href="/laundry/ship"><?= te('laundry.home.ship') ?></a>
        <a class="btn-secondary" href="/laundry/accept"><?= te('laundry.home.accept') ?></a>
      <?php endif; ?>
      <?php if ($canEdit): ?>
        <a class="btn-secondary" href="/laundry/inventory"><?= te('laundry.home.inventory') ?></a>
        <a class="btn-secondary" href="/laundry/settings"><?= te('laundry.menu.settings') ?></a>
      <?php endif; ?>
      <a class="btn-secondary" href="/laundry/moves"><?= te('laundry.menu.moves') ?></a>
      <a class="btn-secondary" href="/laundry/shipments"><?= te('laundry.menu.shipments') ?></a>
    </div>
  </div>
</section>
<?php if (!$started): ?>
  <div class="flash flash-warn"><?= te('laundry.balances.none') ?></div>
<?php endif; ?>
<p class="muted"><strong><?= te('laundry.balances.title') ?></strong></p>
<div class="table-wrap"><table class="data">
<thead><tr>
  <th><?= te('laundry.col.account') ?></th>
  <?php foreach ($types as $t): ?><th><?= e((string)$t['name']) ?></th><?php endforeach; ?>
  <th><?= te('laundry.balances.total') ?></th>
</tr></thead>
<tbody>
<?php foreach ($accounts as $aid => $aname): ?>
  <?php $rowTotal = 0; ?>
  <tr>
    <td><?= e($aname) ?></td>
    <?php foreach ($types as $t): ?>
      <?php $b = (int)($matrix[(int)$aid][(int)$t['id']] ?? 0); $rowTotal += $b; ?>
      <td class="<?= $b < 0 ? 'bad-text' : '' ?>"><strong><?= $b ?></strong></td>
    <?php endforeach; ?>
    <td><strong><?= $rowTotal ?></strong></td>
  </tr>
<?php endforeach; ?>
</tbody></table></div>

<h2><?= te('laundry.home.open_shipments') ?></h2>
<?php if ($open === []): ?>
  <p class="muted"><?= te('laundry.list.empty') ?></p>
<?php else: ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <th>ID</th>
  <th><?= te('laundry.col.dt') ?></th>
  <th><?= te('laundry.col.status') ?></th>
  <th><?= te('laundry.col.type') ?></th>
</tr></thead>
<tbody>
<?php foreach ($open as $s): ?>
  <tr>
    <td>#<?= (int)$s['id'] ?></td>
    <td class="muted"><?= (int)$s['dt'] > 0 ? e(date('d.m.Y H:i', (int)$s['dt'])) : '—' ?></td>
    <td><?= e(t('laundry.status.' . (string)$s['status'])) ?></td>
    <td><?php
      $bits = [];
      foreach ($s['lines'] ?? [] as $l) {
          $tid = (int)$l['id_item_type'];
          $bits[] = ($typeNames[$tid] ?? ('#' . $tid)) . ': ' . (int)$l['qty_open'];
      }
      echo e(implode(', ', $bits));
    ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
