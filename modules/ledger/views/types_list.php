<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var array<int,string> $names */
/** @var array<int,string> $accounts */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/account-move-types';
$listId = 'ledger_types';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$colSpan = 6 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row"><div></div><div class="page-actions"><a class="btn-primary" href="/account-move-types/new"><?= te('core.common.add') ?></a></div></div>
  <?php endif; ?>
</section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('ledger.types.search') ?>" data-filter-debounce>
</form>
<?= $pager ?>
<?= BulkActions::beginForm($listId, ListQuery::url($path, $filters, [])) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('ledger.types.col.name'), 'name', $sort, $dir, $path, $filters) ?>
  <th><?= te('ledger.types.col.parent') ?></th>
  <th><?= te('ledger.types.col.direction') ?></th>
  <?= ListQuery::sortTh(t('ledger.types.col.enabled'), 'enabled', $sort, $dir, $path, $filters) ?>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr>
  <?= BulkActions::td($listId, (int)$r['id']) ?>
  <td class="muted"><?php if ($canEdit): ?><a href="/account-move-types/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a><?php else: ?><?= (int)$r['id'] ?><?php endif; ?></td>
  <td><?= e((string)$r['name']) ?></td>
  <td class="muted"><?= (int)$r['pid'] > 0 ? e((string)($names[(int)$r['pid']] ?? ('#' . (int)$r['pid']))) : '—' ?></td>
  <td><?= e(LedgerMovesRepository::directionLabel((int)$r['direction'])) ?></td>
  <td><?= !empty($r['enabled']) ? te('core.common.yes') : te('core.common.no') ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('ledger.types.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
