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
/** @var array|null $bulkFlash */
$path = '/laundry/types';
$listId = 'laundry_types';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$colSpan = 5 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head">
  <div class="page-head-row">
    <h1><?= te('laundry.menu.types') ?></h1>
    <div class="page-actions">
      <?php if ($canEdit): ?><a class="btn-primary" href="/laundry/types/new"><?= te('core.common.add') ?></a><?php endif; ?>
      <a class="btn-secondary" href="/laundry/settings"><?= te('laundry.menu.settings') ?></a>
    </div>
  </div>
</section>
<?php if (!empty($bulkFlash['error'])): ?><div class="flash flash-warn"><?= e((string)$bulkFlash['error']) ?></div><?php endif; ?>
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
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('laundry.col.code'), 'code', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('laundry.col.name'), 'name', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('laundry.col.sort'), 'sort', $sort, $dir, $path, $filters) ?>
  <th><?= te('laundry.col.enabled') ?></th>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr>
  <?= BulkActions::td($listId, (int)$r['id']) ?>
  <td><?php if ($canEdit): ?><a href="/laundry/types/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a><?php else: ?><?= (int)$r['id'] ?><?php endif; ?></td>
  <td class="muted"><?= e((string)$r['code']) ?></td>
  <td><?= e((string)$r['name']) ?></td>
  <td><?= (int)$r['sort'] ?></td>
  <td><?= !empty($r['enabled']) ? te('core.common.on') : te('core.common.off') ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('laundry.list.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
