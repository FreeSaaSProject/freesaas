<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $idAct */
/** @var int $idGood */
/** @var array<int,string> $products */
/** @var array<int,string> $stocks */
/** @var bool $canEdit */
/** @var bool $canWriteoff */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/stickers/ins';
$listId = 'stickers_ins';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$colSpan = 8 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row"><div></div><div class="page-actions">
    <a class="btn-primary" href="/stickers/ins/new<?= $idAct > 0 ? ('?id_act=' . $idAct) : '' ?>"><?= te('core.common.add') ?></a>
  </div></div>
  <?php endif; ?>
</section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('stickers.list.search_placeholder') ?>" data-filter-debounce>
  <input type="number" name="id_act" value="<?= $idAct > 0 ? $idAct : '' ?>" placeholder="<?= te('stickers.col.act') ?>">
  <input type="number" name="id_good" value="<?= $idGood > 0 ? $idGood : '' ?>" placeholder="<?= te('stickers.col.good') ?>">
</form>
<?= $pager ?>
<?= BulkActions::beginForm($listId, ListQuery::url($path, $filters, [])) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <th><?= te('stickers.col.act') ?></th>
  <th><?= te('stickers.col.good') ?></th>
  <th><?= te('stickers.col.stock') ?></th>
  <th><?= te('stickers.col.left') ?></th>
  <th><?= te('stickers.col.pin') ?></th>
  <th></th>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
  <?php $left = round((float)$r['qtyu_in'] - (float)$r['qtyu_out'], 3); ?>
  <tr>
    <?= BulkActions::td($listId, (int)$r['id']) ?>
    <td><?php if ($canEdit): ?><a href="/stickers/ins/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a><?php else: ?><?= (int)$r['id'] ?><?php endif; ?></td>
    <td><a href="/stickers/receipts/<?= (int)$r['id_act'] ?>/edit"><?= (int)$r['id_act'] ?></a></td>
    <td><?= e($products[(int)$r['id_good']] ?? ('#' . (int)$r['id_good'])) ?></td>
    <td><?= e($stocks[(int)$r['id_stock']] ?? ('#' . (int)$r['id_stock'])) ?></td>
    <td><?= e((string)$left) ?></td>
    <td><code><?= e((string)$r['pin']) ?></code></td>
    <td><?php if ($canWriteoff && $left > 0): ?><a href="/stickers/writeoff?id_sticker=<?= (int)$r['id'] ?>"><?= te('stickers.writeoff.submit') ?></a><?php endif; ?></td>
  </tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('stickers.list.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
