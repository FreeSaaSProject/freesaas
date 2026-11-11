<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $tree */
/** @var string $q */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $perPage */
/** @var array $filters */
/** @var array|null $bulkFlash */
/** @var array{ok:bool,message:string}|null $accrualFlash */
/** @var list<array<string,mixed>> $accrualRows */
$path = '/funds'; $listId = 'funds';
$colSpan = 4 + BulkActions::colSpanExtra($listId);
$grandTotal = 0.0;
foreach ($tree as $node) {
    $grandTotal += (float)$node['balance'];
}
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
<p class="muted"><?= te('funds.list.hint') ?></p>
<?= BulkActions::beginForm($listId, ListQuery::url($path, $filters, [])) ?><?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data funds-table">
<thead><tr><?= BulkActions::th($listId) ?><th>ID</th><th><?= te('funds.col.name') ?></th><th><?= te('funds.col.type') ?></th><th class="num"><?= te('funds.col.balance') ?></th></tr></thead>
<tbody>
<?php foreach ($tree as $node): ?>
<tr class="<?= $node['is_group'] ? 'funds-group-row' : '' ?>">
  <?= BulkActions::td($listId, (int)$node['id']) ?>
  <td class="muted"><?php if ($canEdit): ?><a href="/funds/<?= (int)$node['id'] ?>/edit"><?= (int)$node['id'] ?></a><?php else: ?><?= (int)$node['id'] ?><?php endif; ?></td>
  <td><a href="/funds/<?= (int)$node['id'] ?>"><?= e((string)$node['name']) ?></a>
    <?php if ($node['is_group']): ?> <span class="muted funds-count"><?= te('funds.list.children', ['n' => count($node['children'])]) ?></span><?php endif; ?></td>
  <td class="muted"><?= e(FundsRepository::typeLabel((int)$node['type'])) ?></td>
  <td class="num"><?= e(FundMovementsRepository::fmt((float)$node['balance'])) ?></td>
</tr>
<?php foreach ($node['children'] as $c): ?>
<tr class="funds-child-row">
  <?= BulkActions::td($listId, (int)$c['id']) ?>
  <td class="muted"><?php if ($canEdit): ?><a href="/funds/<?= (int)$c['id'] ?>/edit"><?= (int)$c['id'] ?></a><?php else: ?><?= (int)$c['id'] ?><?php endif; ?></td>
  <td class="funds-name"><a href="/funds/<?= (int)$c['id'] ?>"><?= e((string)$c['name']) ?></a></td>
  <td class="muted"><?= e(FundsRepository::typeLabel((int)$c['type'])) ?></td>
  <td class="num"><?= e(FundMovementsRepository::fmt((float)$c['balance'])) ?></td>
</tr>
<?php endforeach; ?>
<?php endforeach; ?>
<?php if (!$tree): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('funds.list.empty') ?></td></tr><?php endif; ?>
</tbody>
<?php if ($tree): ?>
<tfoot><tr><td colspan="<?= (int)$colSpan - 1 ?>"><?= te('funds.view.total') ?></td><td class="num"><strong><?= e(FundMovementsRepository::fmt($grandTotal)) ?></strong></td></tr></tfoot>
<?php endif; ?>
</table></div>
<?= BulkActions::endForm($listId) ?>
