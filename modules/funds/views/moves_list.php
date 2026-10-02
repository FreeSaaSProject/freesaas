<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $fundId */
/** @var int $typeId */
/** @var array<int,string> $funds */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
/** @var int $fundType */
/** @var int $year */
/** @var int $month */
$path = '/fund-movements'; $listId = 'fund_movements';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$colSpan = 6 + BulkActions::colSpanExtra($listId);
$fundType = (int)($fundType ?? 0);
$year = (int)($year ?? 0);
$month = (int)($month ?? 0);
?>
<section class="page-head"><?php if ($canEdit): ?><div class="page-head-row"><div></div><div class="page-actions"><a class="btn-primary" href="/fund-movements/new"><?= te('core.common.add') ?></a></div></div><?php endif; ?></section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('funds.moves.search') ?>" data-filter-debounce>
  <label><?= te('funds.moves.filter_fund') ?><select name="fund_id"><option value="0"><?= te('funds.moves.filter_all') ?></option><?php foreach ($funds as $fid => $fname): ?><option value="<?= (int)$fid ?>"<?= (int)$fundId === (int)$fid ? ' selected' : '' ?>><?= e($fname) ?></option><?php endforeach; ?></select></label>
  <label><?= te('funds.moves.filter_type') ?><select name="type_id"><option value="0"><?= te('funds.moves.filter_all') ?></option><?php foreach (array_keys(FundMovementsRepository::typeDirections()) as $t): ?><option value="<?= (int)$t ?>"<?= (int)$typeId === (int)$t ? ' selected' : '' ?>><?= e(FundMovementsRepository::typeLabel((int)$t)) ?></option><?php endforeach; ?></select></label>
  <label><?= te('funds.moves.filter_fund_type') ?><select name="fund_type"><option value="0"><?= te('funds.moves.filter_all') ?></option><option value="1"<?= $fundType === 1 ? ' selected' : '' ?>><?= te('funds.type.1') ?></option><option value="2"<?= $fundType === 2 ? ' selected' : '' ?>><?= te('funds.type.2') ?></option></select></label>
  <label><?= te('funds.moves.filter_year') ?><input type="number" name="year" min="0" value="<?= $year > 0 ? (int)$year : '' ?>" placeholder="YYYY" style="width:5rem"></label>
  <label><?= te('funds.moves.filter_month') ?><input type="number" name="month" min="0" max="12" value="<?= $month > 0 ? (int)$month : '' ?>" placeholder="MM" style="width:4rem"></label>
  <?php if (!empty($filters['yearE'])): ?><input type="hidden" name="yearE" value="<?= e((string)$filters['yearE']) ?>"><?php endif; ?>
  <?php if (!empty($filters['monthE'])): ?><input type="hidden" name="monthE" value="<?= e((string)$filters['monthE']) ?>"><?php endif; ?>
</form>
<?= $pager ?>
<?= BulkActions::beginForm($listId, ListQuery::url($path, $filters, [])) ?><?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data">
<thead><tr><?= BulkActions::th($listId) ?><?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?><th><?= te('funds.moves.col.fund') ?></th><th><?= te('funds.moves.col.type') ?></th><?= ListQuery::sortTh(t('funds.moves.col.sum'), 'sum', $sort, $dir, $path, $filters) ?><th><?= te('funds.moves.col.name') ?></th></tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr>
  <?= BulkActions::td($listId, (int)$r['id']) ?>
  <td class="muted"><?php if ($canEdit): ?><a href="/fund-movements/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a><?php else: ?><?= (int)$r['id'] ?><?php endif; ?></td>
  <td><?= e((string)($funds[(int)$r['fund_id']] ?? '#' . (int)$r['fund_id'])) ?></td>
  <td><?= e(FundMovementsRepository::typeLabel((int)$r['type_id'])) ?></td>
  <td><?= ((int)$r['direction'] < 0 ? '−' : '+') ?> <?= e(number_format((float)$r['summ'], 2, '.', ' ')) ?></td>
  <td><?= e((string)$r['name']) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('funds.moves.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?><?= $pager ?>
