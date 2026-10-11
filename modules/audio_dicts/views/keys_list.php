<?php
declare(strict_types=1);
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
/** @var int $dict */
/** @var array<int,string> $dictOptions */
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 6 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
$dict = (int)($dict ?? 0);
$dictOptions = is_array($dictOptions ?? null) ? $dictOptions : [];
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row"><div></div><div class="page-actions"><a class="btn-primary" href="<?= e($path) ?>/new"><?= te('core.common.add') ?></a></div></div>
  <?php endif; ?>
</section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('audio_dicts.search_placeholder') ?>" data-filter-debounce>
  <label><?= te('audio_dicts.col.dict') ?>
    <select name="dict" data-filter-change>
      <option value="0"><?= te('audio_dicts.filter.all_dicts') ?></option>
      <?php foreach ($dictOptions as $did => $dname): ?>
        <option value="<?= (int)$did ?>"<?= $dict === (int)$did ? ' selected' : '' ?>><?= e($dname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</form>
<?= $pager ?>
<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('audio_dicts.col.name'), 'name', $sort, $dir, $path, $filters) ?>
  <th><?= te('audio_dicts.col.dict') ?></th>
  <th><?= te('audio_dicts.col.type') ?></th>
  <?= ListQuery::sortTh(t('audio_dicts.col.disabled'), 'disabled', $sort, $dir, $path, $filters) ?>
</tr></thead>
<tbody>
<?php foreach ($items as $c): ?>
<tr>
  <?= BulkActions::td($listId, (int)$c['id']) ?>
  <td class="muted"><?php if ($canEdit): ?><a href="<?= e($path) ?>/<?= (int)$c['id'] ?>/edit"><?= (int)$c['id'] ?></a><?php else: ?><?= (int)$c['id'] ?><?php endif; ?></td>
  <td><?= e((string)$c['name']) ?></td>
  <td><?= e((string)($c['dict_name'] ?: '—')) ?></td>
  <td><?= e((string)($c['type_name'] ?: '—')) ?></td>
  <td><?= !empty($c['disabled']) ? te('core.common.on') : te('core.common.off') ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('audio_dicts.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
