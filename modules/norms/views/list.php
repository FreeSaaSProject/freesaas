<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var int $idProc */
/** @var int $idGood */
/** @var array<int,string> $products */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/norms';
$listId = 'norms';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$colSpan = 5 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row"><div></div><div class="page-actions">
    <a class="btn-primary" href="/norms/new<?= $idProc > 0 ? ('?id_proc=' . $idProc) : '' ?>"><?= te('core.common.add') ?></a>
  </div></div>
  <?php endif; ?>
</section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="number" name="id_proc" value="<?= $idProc > 0 ? $idProc : '' ?>" placeholder="<?= te('norms.col.proc') ?>">
  <input type="number" name="id_good" value="<?= $idGood > 0 ? $idGood : '' ?>" placeholder="<?= te('norms.col.good') ?>">
</form>
<?= $pager ?>
<?= BulkActions::beginForm($listId, ListQuery::url($path, $filters, [])) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <th><?= te('norms.col.proc') ?></th>
  <th><?= te('norms.col.good') ?></th>
  <?= ListQuery::sortTh(t('norms.col.qtyu2'), 'qtyu2', $sort, $dir, $path, $filters) ?>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
  <tr>
    <?= BulkActions::td($listId, (int)$r['id']) ?>
    <td><?php if ($canEdit): ?><a href="/norms/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a><?php else: ?><?= (int)$r['id'] ?><?php endif; ?></td>
    <td><?= e($products[(int)$r['id_proc']] ?? ('#' . (int)$r['id_proc'])) ?></td>
    <td><?= e($products[(int)$r['id_good']] ?? ('#' . (int)$r['id_good'])) ?></td>
    <td><?= e((string)$r['qtyu2']) ?></td>
  </tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('norms.list.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
