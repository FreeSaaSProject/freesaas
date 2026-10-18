<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $status */
/** @var array<int,string> $accounts */
/** @var array<int,string> $types */
/** @var string $message */
/** @var bool $canShip */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/laundry/shipments';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
?>
<section class="page-head">
  <div class="page-head-row">
    <h1><?= te('laundry.menu.shipments') ?></h1>
    <div class="page-actions">
      <?php if ($canShip): ?>
        <a class="btn-primary" href="/laundry/ship"><?= te('laundry.home.ship') ?></a>
        <a class="btn-secondary" href="/laundry/accept"><?= te('laundry.home.accept') ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <select name="status" data-auto-submit>
    <option value=""><?= te('laundry.col.status') ?></option>
    <?php foreach (['open', 'partial', 'closed'] as $st): ?>
      <option value="<?= e($st) ?>"<?= $status === $st ? ' selected' : '' ?>><?= te('laundry.status.' . $st) ?></option>
    <?php endforeach; ?>
  </select>
</form>
<?= $pager ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <th>ID</th>
  <th><?= te('laundry.col.dt') ?></th>
  <th><?= te('laundry.col.status') ?></th>
  <th><?= te('laundry.col.account') ?></th>
  <th><?= te('laundry.col.type') ?></th>
  <th><?= te('laundry.col.descr') ?></th>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr>
  <td>#<?= (int)$r['id'] ?></td>
  <td class="muted"><?= (int)$r['dt'] > 0 ? e(date('d.m.Y H:i', (int)$r['dt'])) : '—' ?></td>
  <td><?= te('laundry.status.' . (string)$r['status']) ?></td>
  <td><?= e(($accounts[(int)$r['id_account_from']] ?? '#' . (int)$r['id_account_from'])
      . ' → ' . ($accounts[(int)$r['id_account_to']] ?? '#' . (int)$r['id_account_to'])) ?></td>
  <td><?php
    $bits = [];
    foreach ($r['lines'] ?? [] as $l) {
        $tid = (int)$l['tid'];
        $bits[] = ($types[$tid] ?? ('#' . $tid)) . ': ' . (int)$l['ret'] . '/' . (int)$l['sent'];
    }
    echo e(implode(', ', $bits));
  ?></td>
  <td><?= e((string)$r['descr']) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="6" class="muted"><?= te('laundry.list.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= $pager ?>
