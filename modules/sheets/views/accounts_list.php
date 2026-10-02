<?php
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
/** @var array|null $bulkFlash */
$path = '/sheets/accounts';
$listId = 'sheet_accounts';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$colSpan = 5 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head"><?php if ($canEdit): ?><div class="page-head-row"><div></div><div class="page-actions"><a class="btn-primary" href="/sheets/accounts/new"><?= te('core.common.add') ?></a></div></div><?php endif; ?></section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('sheets.list.search_placeholder') ?>" data-filter-debounce>
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
<thead><tr><?= BulkActions::th($listId) ?><?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?><?= ListQuery::sortTh(t('sheets.col.name'), 'name', $sort, $dir, $path, $filters) ?><th><?= te('sheets.col.descr') ?></th><th><?= te('sheets.col.balance') ?></th><th><?= te('sheets.col.enabled') ?></th></tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr>
  <?= BulkActions::td($listId, (int)$r['id']) ?>
  <td><?php if ($canEdit): ?><a href="/sheets/accounts/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a><?php else: ?><?= (int)$r['id'] ?><?php endif; ?></td>
  <td><?= e((string)$r['name']) ?></td>
  <td class="muted"><?= e((string)$r['descr']) ?></td>
  <td><a href="/sheets/moves?id_account=<?= (int)$r['id'] ?>"><strong><?= (int)$r['balance'] ?></strong></a></td>
  <td><?= !empty($r['enabled']) ? te('core.common.on') : te('core.common.off') ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('sheets.list.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
