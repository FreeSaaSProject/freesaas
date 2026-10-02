<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $idStock */
/** @var array<int,string> $stocks */
/** @var array<int,string> $suppliers */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/stickers/receipts';
$listId = 'stickers_receipts';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$colSpan = 7 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row"><div></div><div class="page-actions">
    <a class="btn-primary" href="/stickers/receipts/new"><?= te('core.common.add') ?></a>
  </div></div>
  <?php endif; ?>
</section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('stickers.list.search_placeholder') ?>" data-filter-debounce>
  <select name="id_stock" data-auto-submit>
    <option value=""><?= te('stickers.col.stock') ?></option>
    <?php foreach ($stocks as $sid => $sname): ?>
      <option value="<?= (int)$sid ?>"<?= $idStock === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
    <?php endforeach; ?>
  </select>
</form>
<?= $pager ?>
<?= BulkActions::beginForm($listId, ListQuery::url($path, $filters, [])) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('stickers.col.dt'), 'dt_create', $sort, $dir, $path, $filters) ?>
  <th><?= te('stickers.col.descr') ?></th>
  <th><?= te('stickers.col.stock') ?></th>
  <th><?= te('stickers.col.supplier') ?></th>
  <?= ListQuery::sortTh(t('stickers.col.summ'), 'summ', $sort, $dir, $path, $filters) ?>
  <th><?= te('stickers.col.signed') ?></th>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
  <tr>
    <?= BulkActions::td($listId, (int)$r['id']) ?>
    <td><?php if ($canEdit): ?><a href="/stickers/receipts/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a><?php else: ?><?= (int)$r['id'] ?><?php endif; ?></td>
    <td class="muted"><?= (int)$r['dt_create'] > 0 ? e(date('d.m.Y H:i', (int)$r['dt_create'])) : '—' ?></td>
    <td><?= e((string)$r['descr']) ?></td>
    <td><?= e($stocks[(int)$r['id_stock']] ?? ('#' . (int)$r['id_stock'])) ?></td>
    <td><?= e($suppliers[(int)$r['id_supplier']] ?? ('#' . (int)$r['id_supplier'])) ?></td>
    <td><?= e((string)$r['summ']) ?></td>
    <td><?= !empty($r['is_signed']) ? te('core.common.yes') : '' ?></td>
  </tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('stickers.list.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
