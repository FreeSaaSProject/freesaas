<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var string $sort */
/** @var string $dir */
/** @var array $filters */
$path = '/client-statuses';
$sort = $sort ?? 'name';
$dir = $dir ?? 'asc';
$filters = $filters ?? [];
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <p class="lede"><?= t('clients.status.lede') ?></p>
    </div>
    <div class="page-actions">
      <a class="btn-primary" href="/client-statuses/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
</section>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('clients.status.name'), 'name', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('clients.status.discount'), 'discount', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('clients.status.col_price'), 'price_tier', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('clients.status.bonus'), 'bonus', $sort, $dir, $path, $filters) ?>
        <th><?= te('clients.status.block_online_short') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $s): ?>
      <tr>
        <td class="muted"><a href="/client-statuses/<?= (int)$s['id'] ?>/edit"><?= (int)$s['id'] ?></a></td>
        <td>
          <?= e((string)$s['name']) ?>
          <?php if (empty($s['enabled'])): ?>
            <span class="pill off"><?= te('core.common.off') ?></span>
          <?php endif; ?>
          <?php if (!empty($s['block_online'])): ?>
            <span class="pill off"><?= te('clients.status.block_online_short') ?></span>
          <?php endif; ?>
        </td>
        <td><?= e((string)$s['discount']) ?></td>
        <td><?= (int)$s['price_tier'] ?></td>
        <td><?= $s['bonus'] === null || $s['bonus'] === '' ? '—' : e((string)$s['bonus']) ?></td>
        <td><?= !empty($s['block_online']) ? te('core.common.yes') : te('core.common.no') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="6" class="muted"><?= te('clients.status.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
