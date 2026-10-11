<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $idStock */
/** @var array<int,string> $stocks */
/** @var array<int,string> $suppliers */
/** @var array<int, list<array{id:int,summ:float,type_id:int,account_id:int}>> $dpsByAct */
/** @var bool $ledgerOn */
/** @var bool $canEdit */
/** @var bool $canDps */
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
$colSpan = 8 + BulkActions::colSpanExtra($listId);
$dpsByAct = is_array($dpsByAct ?? null) ? $dpsByAct : [];
$ledgerOn = !empty($ledgerOn);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row"><div></div><div class="page-actions">
    <a class="btn-primary" href="/stickers/receipts/new"><?= te('core.common.add') ?></a>
  </div></div>
  <?php endif; ?>
</section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<?php if ($ledgerOn): ?>
  <p class="muted"><?= te('stickers.receipts.dps_hint') ?></p>
<?php endif; ?>
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
<div class="table-wrap"><table class="data data--compact">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('stickers.col.dt'), 'dt_create', $sort, $dir, $path, $filters) ?>
  <th><?= te('stickers.col.descr') ?></th>
  <th><?= te('stickers.col.stock') ?></th>
  <th><?= te('stickers.col.supplier') ?></th>
  <?= ListQuery::sortTh(t('stickers.col.summ'), 'summ', $sort, $dir, $path, $filters) ?>
  <th><?= te('stickers.col.dps') ?></th>
  <th><?= te('stickers.col.signed') ?></th>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
  <?php
    $aid = (int)$r['id'];
    $moves = $dpsByAct[$aid] ?? [];
  ?>
  <tr>
    <?= BulkActions::td($listId, $aid) ?>
    <td><?php if ($canEdit): ?><a href="/stickers/receipts/<?= $aid ?>/edit"><?= $aid ?></a><?php else: ?><?= $aid ?><?php endif; ?></td>
    <td class="muted"><?= (int)$r['dt_create'] > 0 ? e(date('d.m.Y H:i', (int)$r['dt_create'])) : '—' ?></td>
    <td><?= e((string)$r['descr']) ?></td>
    <td><?= e($stocks[(int)$r['id_stock']] ?? ('#' . (int)$r['id_stock'])) ?></td>
    <td><?= e($suppliers[(int)$r['id_supplier']] ?? ('#' . (int)$r['id_supplier'])) ?></td>
    <td><b><?= e((string)$r['summ']) ?></b></td>
    <td class="st-dps">
      <?php if ($moves !== []): ?>
        <?php foreach ($moves as $m): ?>
          <a href="/ledger/moves/<?= (int)$m['id'] ?>/edit">П-<?= (int)$m['id'] ?></a>
          <span class="muted"><?= e((string)$m['summ']) ?></span><br>
        <?php endforeach; ?>
      <?php elseif ($ledgerOn): ?>
        <b class="st-dps-miss"><?= te('stickers.dps.none') ?></b>
      <?php else: ?>
        <span class="muted">—</span>
      <?php endif; ?>
    </td>
    <td><?= !empty($r['is_signed']) ? te('core.common.yes') : '' ?></td>
  </tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('stickers.list.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
<style>.st-dps-miss{color:var(--danger,#c33);font-size:0.85rem}</style>
