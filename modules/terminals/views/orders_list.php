<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $day */
/** @var string $from */
/** @var string $to */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/terminals/orders';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <label><?= te('terminals.fullstats.search_day') ?>
    <input type="date" name="day" value="<?= e($day) ?>">
  </label>
  <label><?= te('terminals.fullstats.from') ?>
    <input type="date" name="from" value="<?= e($from) ?>">
  </label>
  <label><?= te('terminals.fullstats.to') ?>
    <input type="date" name="to" value="<?= e($to) ?>">
  </label>
</form>

<?= $pager ?>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('terminals.orders.col.dt_visit'), 'dt_visit', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('terminals.orders.col.phone'), 'phone', $sort, $dir, $path, $filters) ?>
        <th><?= te('terminals.orders.col.adults') ?></th>
        <th><?= te('terminals.orders.col.children') ?></th>
        <th><?= te('terminals.orders.col.elders') ?></th>
        <th><?= te('terminals.orders.col.total') ?></th>
        <?= ListQuery::sortTh(t('terminals.orders.col.total_summ'), 'total_summ', $sort, $dir, $path, $filters) ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $u): ?>
      <tr>
        <td class="muted"><?= (int)$u['id'] ?></td>
        <td><?= (int)$u['dt_visit'] > 0 ? e(date('Y-m-d', (int)$u['dt_visit'])) : '—' ?></td>
        <td><?= e((string)$u['phone']) ?></td>
        <td><?= (int)$u['adults'] ?></td>
        <td><?= (int)$u['children'] ?></td>
        <td><?= (int)$u['elders'] ?></td>
        <td><?= (int)$u['total'] ?></td>
        <td><?= (int)$u['total_summ'] ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="8" class="muted"><?= te('terminals.orders.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?= $pager ?>
