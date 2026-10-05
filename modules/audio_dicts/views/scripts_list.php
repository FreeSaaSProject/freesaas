<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
/** @var string $path */
/** @var string $listId */
/** @var string $error */
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 5 + BulkActions::colSpanExtra($listId) + ($canEdit ? 1 : 0);
$actionUrl = ListQuery::url($path, $filters, []);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row"><div></div><div class="page-actions"><a class="btn-primary" href="<?= e($path) ?>/new"><?= te('audio_dicts.scripts.btn_new') ?></a></div></div>
  <?php endif; ?>
</section>
<?php if ($error !== ''): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('audio_dicts.search_placeholder') ?>" data-filter-debounce>
</form>
<?= $pager ?>
<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('audio_dicts.col.name'), 'name', $sort, $dir, $path, $filters) ?>
  <th><?= te('audio_dicts.scripts.col.parts') ?></th>
  <?= ListQuery::sortTh(t('audio_dicts.col.disabled'), 'disabled', $sort, $dir, $path, $filters) ?>
  <?php if ($canEdit): ?><th></th><?php endif; ?>
</tr></thead>
<tbody>
<?php foreach ($items as $c): ?>
<tr>
  <?= BulkActions::td($listId, (int)$c['id']) ?>
  <td class="muted"><a href="<?= e($path) ?>/<?= (int)$c['id'] ?>"><?= (int)$c['id'] ?></a></td>
  <td><a href="<?= e($path) ?>/<?= (int)$c['id'] ?>"><?= e((string)$c['name']) ?></a></td>
  <td class="muted"><?= (int)$c['parts_count'] ?></td>
  <td><?= !empty($c['disabled']) ? te('core.common.on') : te('core.common.off') ?></td>
  <?php if ($canEdit): ?>
  <td class="row-actions">
    <a class="btn-secondary btn-sm" href="<?= e($path) ?>/<?= (int)$c['id'] ?>/copy"><?= te('audio_dicts.scripts.btn_copy') ?></a>
  </td>
  <?php endif; ?>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('audio_dicts.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
