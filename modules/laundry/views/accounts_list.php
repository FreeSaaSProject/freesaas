<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $enabled */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/laundry/accounts';
$listId = 'laundry_accounts';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$colSpan = 4 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head"><?php if ($canEdit): ?><div class="page-head-row"><div></div><div class="page-actions"><a class="btn-primary" href="/laundry/accounts/new"><?= te('core.common.add') ?></a></div></div><?php endif; ?></section>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('laundry.list.search_placeholder') ?>" data-filter-debounce>
  <select name="enabled" data-auto-submit>
    <option value=""><?= te('core.common.all') ?></option>
    <option value="1"<?= $enabled === '1' ? ' selected' : '' ?>><?= te('core.common.on') ?></option>
    <option value="0"<?= $enabled === '0' ? ' selected' : '' ?>><?= te('core.common.off') ?></option>
  </select>
</form>
<?= $pager ?>
<?= BulkActions::beginForm($listId, ListQuery::url($path, $filters, [])) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data">
<thead><tr><?= BulkActions::th($listId) ?><?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?><?= ListQuery::sortTh(t('laundry.col.name'), 'name', $sort, $dir, $path, $filters) ?><th><?= te('laundry.col.descr') ?></th><th><?= te('laundry.col.enabled') ?></th></tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr>
  <?= BulkActions::td($listId, (int)$r['id']) ?>
  <td><?php if ($canEdit): ?><a href="/laundry/accounts/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a><?php else: ?><?= (int)$r['id'] ?><?php endif; ?></td>
  <td><?= e((string)$r['name']) ?></td>
  <td class="muted"><?= e((string)$r['descr']) ?></td>
  <td><?= !empty($r['enabled']) ? te('core.common.on') : te('core.common.off') ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('laundry.list.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
