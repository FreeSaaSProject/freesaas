<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var array<int,string> $names */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
/** @var array{ok:bool,message:string}|null $accrualFlash */
/** @var list<array<string,mixed>> $accrualRows */
$path = '/funds'; $listId = 'funds';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$colSpan = 5 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/funds/new"><?= te('core.common.add') ?></a>
      <form method="post" style="display:inline" onsubmit="return confirm(<?= tj('funds.accrual.confirm_calc') ?>)">
        <input type="hidden" name="funds_action" value="calculate">
        <button class="btn-secondary" type="submit"><?= te('funds.accrual.calculate') ?></button>
      </form>
      <form method="post" style="display:inline" onsubmit="return confirm(<?= tj('funds.accrual.confirm_reprice') ?>)">
        <input type="hidden" name="funds_action" value="reprice">
        <button class="btn-secondary" type="submit"><?= te('funds.accrual.reprice') ?></button>
      </form>
    </div>
  </div>
  <?php endif; ?>
</section>
<?php if (!empty($accrualFlash)): ?>
  <div class="flash <?= !empty($accrualFlash['ok']) ? 'flash-ok' : 'flash-warn' ?>"><?= e((string)$accrualFlash['message']) ?></div>
<?php endif; ?>
<?php if (!empty($accrualRows)): ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <th><?= te('funds.accrual.col.fund') ?></th>
  <th><?= te('funds.accrual.col.type') ?></th>
  <th><?= te('funds.accrual.col.balance') ?></th>
  <th><?= te('funds.accrual.col.os') ?></th>
  <th><?= te('funds.accrual.col.months') ?></th>
  <th><?= te('funds.accrual.col.pay') ?></th>
  <th><?= te('funds.accrual.col.status') ?></th>
</tr></thead>
<tbody>
<?php foreach ($accrualRows as $ar): ?>
<tr>
  <td><?= e((string)$ar['name']) ?></td>
  <td><?= e((string)$ar['type']) ?></td>
  <td><?= e(number_format((float)$ar['balance'], 2, '.', ' ')) ?></td>
  <td><?= e(number_format((float)$ar['os'], 2, '.', ' ')) ?></td>
  <td><?= (int)$ar['months_left'] ?> / <?= (int)$ar['months_plus'] ?></td>
  <td><?= e(number_format((float)$ar['payment'], 2, '.', ' ')) ?></td>
  <td class="muted"><?= e((string)$ar['status']) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('funds.list.search') ?>" data-filter-debounce>
</form>
<?= $pager ?>
<?= BulkActions::beginForm($listId, ListQuery::url($path, $filters, [])) ?><?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data">
<thead><tr><?= BulkActions::th($listId) ?><?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?><?= ListQuery::sortTh(t('funds.col.name'), 'name', $sort, $dir, $path, $filters) ?><th><?= te('funds.col.parent') ?></th><th><?= te('funds.col.balance') ?></th></tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr>
  <?= BulkActions::td($listId, (int)$r['id']) ?>
  <td class="muted"><?php if ($canEdit): ?><a href="/funds/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a><?php else: ?><?= (int)$r['id'] ?><?php endif; ?></td>
  <td><?= e((string)$r['name']) ?> <span class="muted"><?= e(FundsRepository::typeLabel((int)$r['type'])) ?></span></td>
  <td class="muted"><?= (int)$r['pid'] > 0 ? e((string)($names[(int)$r['pid']] ?? '#' . (int)$r['pid'])) : '—' ?></td>
  <td><?= e(number_format((float)$r['balance'], 2, '.', ' ')) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('funds.list.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?><?= $pager ?>
