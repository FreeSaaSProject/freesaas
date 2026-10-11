<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var array{price:float,qty:float,summ:float,qtyu_in:float,qtyu_out:float,qtyu_left:float,unit_price_sum:float,summ_left:float} $sums */
$sums = is_array($sums ?? null) ? $sums : StickerInsRepository::emptySums();
/** @var array<string,string> $f */
/** @var string $year */
/** @var string $month */
/** @var int $idAct */
/** @var int $idGood */
/** @var int $idStock */
/** @var int $idSupplier */
/** @var int $nonAct */
/** @var int $revi */
/** @var int $qqr */
/** @var int $weightable */
/** @var int $isUsed */
/** @var list<int> $years */
/** @var array<int,string> $products */
/** @var array<int,string> $materials */
/** @var array<int,string> $stocks */
/** @var array<int,string> $suppliers */
/** @var array<int,array{unit:string,unitu:string}> $productUnits */
$materials = is_array($materials ?? null) ? $materials : [];
$productUnits = is_array($productUnits ?? null) ? $productUnits : [];
/** @var array<string,mixed>|null $stockOps */
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
$colSpan = 7 + BulkActions::colSpanExtra($listId);
$months = [
    1 => t('stickers.filter.m1'), 2 => t('stickers.filter.m2'), 3 => t('stickers.filter.m3'),
    4 => t('stickers.filter.m4'), 5 => t('stickers.filter.m5'), 6 => t('stickers.filter.m6'),
    7 => t('stickers.filter.m7'), 8 => t('stickers.filter.m8'), 9 => t('stickers.filter.m9'),
    10 => t('stickers.filter.m10'), 11 => t('stickers.filter.m11'), 12 => t('stickers.filter.m12'),
];
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row"><div></div><div class="page-actions">
    <a class="btn-secondary" href="/stickers/ins/print"><?= te('stickers.menu.print') ?></a>
    <a class="btn-primary" href="/stickers/ins/new<?= $idAct > 0 ? ('?id_act=' . $idAct) : '' ?>"><?= te('core.common.add') ?></a>
  </div></div>
  <?php endif; ?>
</section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>

<form class="filters filters--dense" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="<?= te('stickers.filter.q') ?>" data-filter-debounce title="<?= te('stickers.filter.q') ?>">
  <input type="search" name="good_q" class="filters-good" value="<?= e($f['good_q'] ?? '') ?>" placeholder="<?= te('stickers.filter.good_q') ?>" data-filter-debounce list="stickers-materials" title="<?= te('stickers.filter.good_q') ?>">
  <input type="hidden" name="id_good" value="<?= $idGood > 0 ? $idGood : '' ?>">
  <datalist id="stickers-materials">
    <?php foreach ($materials as $mid => $pname): ?>
      <option value="<?= e($pname) ?>" data-id="<?= (int)$mid ?>"></option>
    <?php endforeach; ?>
  </datalist>
  <select name="year" title="<?= te('stickers.filter.year') ?>">
    <option value="all"><?= te('stickers.filter.year') ?></option>
    <?php foreach ($years as $y): ?>
      <option value="<?= (int)$y ?>"<?= (string)$year === (string)$y ? ' selected' : '' ?>><?= (int)$y ?></option>
    <?php endforeach; ?>
  </select>
  <select name="month" title="<?= te('stickers.filter.month') ?>">
    <option value="all"><?= te('stickers.filter.month') ?></option>
    <?php foreach ($months as $mi => $ml): ?>
      <option value="<?= (int)$mi ?>"<?= (string)$month === (string)$mi ? ' selected' : '' ?>><?= e($ml) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="id_stock" title="<?= te('stickers.col.stock') ?>">
    <option value=""><?= te('stickers.filter.stock') ?></option>
    <?php foreach ($stocks as $sid => $sname): ?>
      <option value="<?= (int)$sid ?>"<?= $idStock === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="id_supplier" title="<?= te('stickers.filter.supplier') ?>">
    <option value=""><?= te('stickers.filter.supplier') ?></option>
    <?php foreach ($suppliers as $sid => $sname): ?>
      <option value="<?= (int)$sid ?>"<?= $idSupplier === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="number" name="id_act" value="<?= $idAct > 0 ? $idAct : '' ?>" placeholder="<?= te('stickers.col.act') ?>" title="<?= te('stickers.col.act') ?>">
  <select name="non_act" title="<?= te('stickers.filter.non_act') ?>">
    <option value="0"><?= te('stickers.filter.non_act_all') ?></option>
    <option value="1"<?= $nonAct === 1 ? ' selected' : '' ?>><?= te('stickers.filter.non_act_no') ?></option>
    <option value="2"<?= $nonAct === 2 ? ' selected' : '' ?>><?= te('stickers.filter.non_act_yes') ?></option>
  </select>
  <select name="revi" title="<?= te('stickers.filter.revi') ?>">
    <option value="0"><?= te('stickers.filter.revi_all') ?></option>
    <option value="1"<?= $revi === 1 ? ' selected' : '' ?>><?= te('stickers.filter.revi_yes') ?></option>
    <option value="2"<?= $revi === 2 ? ' selected' : '' ?>><?= te('stickers.filter.revi_no') ?></option>
  </select>
  <select name="qqr" title="<?= te('stickers.filter.qqr') ?>">
    <option value="0"><?= te('stickers.filter.qqr_all') ?></option>
    <option value="1"<?= $qqr === 1 ? ' selected' : '' ?>><?= te('stickers.filter.qqr_one') ?></option>
    <option value="2"<?= $qqr === 2 ? ' selected' : '' ?>><?= te('stickers.filter.qqr_multi') ?></option>
  </select>
  <select name="weightable" title="<?= te('stickers.filter.weight') ?>">
    <option value="0"><?= te('stickers.filter.weight_all') ?></option>
    <option value="1"<?= $weightable === 1 ? ' selected' : '' ?>><?= te('stickers.filter.weight_no') ?></option>
    <option value="2"<?= $weightable === 2 ? ' selected' : '' ?>><?= te('stickers.filter.weight_yes') ?></option>
  </select>
  <select name="is_used" title="<?= te('stickers.filter.used') ?>">
    <option value="0"><?= te('stickers.filter.used_all') ?></option>
    <option value="1"<?= $isUsed === 1 ? ' selected' : '' ?>><?= te('stickers.filter.used_no') ?></option>
    <option value="2"<?= $isUsed === 2 ? ' selected' : '' ?>><?= te('stickers.filter.used_yes') ?></option>
  </select>
</form>

<?php if (is_array($stockOps)): ?>
<form method="post" action="<?= e(ListQuery::url($path, $filters, [])) ?>" class="stickers-stock-ops filters--dense">
  <?= Csrf::field() ?>
  <input type="hidden" name="id_stock" value="<?= (int)$stockOps['id_stock'] ?>">
  <span class="muted"><?= te('stickers.bulk.stock_ops', ['stock' => (string)$stockOps['stock_name']]) ?></span>
  <?php if (!empty($stockOps['can_revi_on'])): ?>
    <button type="submit" name="stock_op" value="revi_on" class="btn-secondary btn-sm"><?= te('stickers.bulk.revi_on') ?></button>
  <?php endif; ?>
  <?php if (!empty($stockOps['can_revi_off'])): ?>
    <button type="submit" name="stock_op" value="revi_off" class="btn-secondary btn-sm" onclick="return confirm(<?= tj('stickers.bulk.confirm_revi_off') ?>)"><?= te('stickers.bulk.revi_off') ?></button>
  <?php endif; ?>
  <?php if (!empty($stockOps['can_move_on'])): ?>
    <button type="submit" name="stock_op" value="move_on" class="btn-secondary btn-sm"><?= te('stickers.bulk.move_on') ?></button>
  <?php endif; ?>
  <?php if (!empty($stockOps['can_move_off'])): ?>
    <button type="submit" name="stock_op" value="move_off" class="btn-secondary btn-sm"><?= te('stickers.bulk.move_off') ?></button>
  <?php endif; ?>
  <?php if (empty($stockOps['avail'])): ?>
    <span class="muted"><?= te('stickers.bulk.hint_need_activated') ?></span>
  <?php elseif (empty($stockOps['can_revi_on']) && empty($stockOps['can_move_on']) && empty($stockOps['can_revi_off']) && empty($stockOps['can_move_off'])): ?>
    <span class="muted"><?= te('stickers.bulk.hint_mode_busy') ?></span>
  <?php endif; ?>
</form>
<?php elseif ($canEdit): ?>
  <p class="muted stickers-stock-ops-hint"><?= te('stickers.bulk.hint_pick_stock') ?></p>
<?php endif; ?>

<?= $pager ?>
<?= BulkActions::beginForm($listId, ListQuery::url($path, $filters, [])) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data data--compact stickers-ins-table">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <th><?= te('stickers.col.good') ?></th>
  <th><?= te('stickers.col.qty_block') ?></th>
  <th><?= te('stickers.col.left') ?></th>
  <th><?= te('stickers.col.expire') ?></th>
  <th><?= te('stickers.col.flags') ?></th>
  <th></th>
</tr></thead>
<tbody>
<?php
$bulkExtra = BulkActions::colSpanExtra($listId);
$avgUnit = ((int)$total > 0) ? round((float)$sums['unit_price_sum'] / (int)$total, 2) : 0.0;
?>
<?php foreach ($items as $r): ?>
  <?php
    $left = round((float)$r['qtyu_in'] - (float)$r['qtyu_out'], 3);
    $rate = (float)($r['qty_qtyu_rate'] ?? 1);
    if ($rate <= 0) {
        $rate = 1.0;
    }
    $price = (float)($r['price'] ?? 0);
    $unitPrice = round($price / $rate, 2);
    $pu = $productUnits[(int)$r['id_good']] ?? ['unit' => '', 'unitu' => ''];
    $unitu = $pu['unitu'] !== '' ? $pu['unitu'] : t('stickers.col.ue');
    $unit = $pu['unit'] !== '' ? $pu['unit'] : t('stickers.col.e');
    $flags = [];
    if ((int)($r['dt_print'] ?? 0) < 1 || (int)($r['dt_reprint'] ?? 0) > 0) {
        $flags[] = t('stickers.flag.not_act');
    } else {
        $flags[] = t('stickers.flag.act');
    }
    if ((int)($r['dt_revision'] ?? 0) > 0) {
        $flags[] = t('stickers.flag.revi');
    }
    if ((int)($r['dt_movement'] ?? 0) > 0) {
        $flags[] = t('stickers.flag.move');
    }
    $exp = (int)($r['dt_expire'] ?? 0);
    $expHtml = '—';
    if ($exp > 0) {
        $days = round(($exp - time()) / 86400, 1);
        $cls = $exp < time() ? 'st-exp--bad' : ($exp < time() + 7 * 86400 ? 'st-exp--warn' : 'st-exp--ok');
        $expHtml = '<span class="' . $cls . '">' . e(date('d.m.Y', $exp)) . ' (' . e((string)$days) . ')</span>';
    }
    $logHtml = StickerInsRepository::formatLogHtml((string)($r['log'] ?? ''));
    $descr = trim((string)($r['descr'] ?? ''));
    $shelf = trim((string)($r['descr_stock'] ?? ''));
  ?>
  <tr>
    <?= BulkActions::td($listId, (int)$r['id']) ?>
    <td class="st-id">
      <?php if ($canEdit): ?><a href="/stickers/ins/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a><?php else: ?><?= (int)$r['id'] ?><?php endif; ?>
      <div class="muted"><code><?= e((string)$r['pin']) ?></code></div>
      <div class="muted"><a href="/stickers/receipts/<?= (int)$r['id_act'] ?>/edit">П-<?= (int)$r['id_act'] ?></a></div>
    </td>
    <td class="st-good">
      <b><?= e($products[(int)$r['id_good']] ?? ('#' . (int)$r['id_good'])) ?></b>
      <div class="muted"><?= e($stocks[(int)$r['id_stock']] ?? ('#' . (int)$r['id_stock'])) ?><?php if ($shelf !== ''): ?> · <?= e($shelf) ?><?php endif; ?></div>
      <?php if ($descr !== ''): ?><div class="muted"><?= e($descr) ?></div><?php endif; ?>
      <?php if ($logHtml !== ''): ?><div class="muted st-log"><span class="st-log-label"><?= te('stickers.col.log') ?>:</span> <?= $logHtml ?></div><?php endif; ?>
    </td>
    <td class="st-qty muted">
      <?= e((string)$price) ?> · <?= e((string)$rate) ?> <?= e($unitu) ?>/<?= e($unit) ?>
      <div><?= e((string)$unitPrice) ?>/<?= e($unitu) ?></div>
      <div><?= te('stickers.col.qtyu_in') ?> <?= e((string)$r['qtyu_in']) ?> · <?= te('stickers.col.qtyu_out') ?> <?= e((string)$r['qtyu_out']) ?></div>
    </td>
    <td><b><?= e((string)$left) ?></b> <span class="muted"><?= e($unitu) ?></span></td>
    <td><?= $expHtml ?></td>
    <td class="muted st-flags"><?= e(implode(' · ', $flags)) ?></td>
    <td><?php if ($canWriteoff && $left > 0): ?>
      <a href="/stickers/writeoff?id_sticker=<?= (int)$r['id'] ?>" title="<?= te('stickers.writeoff.hint_free_act') ?>"><?= te('stickers.writeoff.submit') ?></a>
    <?php endif; ?></td>
  </tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('stickers.list.empty') ?></td></tr><?php endif; ?>
</tbody>
<tfoot>
  <tr class="st-totals">
    <?php if ($bulkExtra > 0): ?><td></td><?php endif; ?>
    <td colspan="2"><b><?= te('stickers.list.totals') ?></b>
      <div class="muted"><?= te('stickers.list.totals_rows', ['n' => (int)$total]) ?></div>
    </td>
    <td class="st-qty">
      <?= e((string)$sums['price']) ?> <?= te('stickers.list.totals_price_sum') ?>
      / <?= e((string)$sums['qty']) ?> <?= te('stickers.col.e') ?>
      / <b><?= e((string)$sums['summ']) ?></b>
      <div class="muted"><?= te('stickers.list.totals_unit_avg') ?>: <?= e((string)$avgUnit) ?>
        (Σ <?= e((string)$sums['unit_price_sum']) ?>)</div>
      <div class="muted"><?= te('stickers.col.qtyu_in') ?> <?= e((string)$sums['qtyu_in']) ?>
        · <?= te('stickers.col.qtyu_out') ?> <?= e((string)$sums['qtyu_out']) ?></div>
    </td>
    <td>
      <b><?= e((string)$sums['qtyu_left']) ?></b>
      <div class="muted"><?= e((string)$sums['summ_left']) ?></div>
    </td>
    <td colspan="3"></td>
  </tr>
</tfoot>
</table></div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
<style>
.filters--dense{gap:0.35rem}
.filters--dense input,.filters--dense select{max-width:9.5rem;min-width:0;padding:0.35rem 0.45rem;font-size:0.85rem;border-radius:8px}
.filters--dense input[type="search"],.filters--dense .filters-good{max-width:14rem;flex:1 1 11rem;min-width:8rem}
.filters--dense input[type="number"]{max-width:5.5rem}
.stickers-stock-ops{display:flex;flex-wrap:wrap;align-items:center;gap:0.4rem;margin:0 0 0.75rem}
.stickers-stock-ops-hint{margin:0 0 0.75rem;font-size:0.9rem}
.btn-sm{padding:0.3rem 0.55rem;font-size:0.85rem}
.stickers-ins-table td{font-size:0.88rem;vertical-align:top;padding:0.35rem 0.45rem}
.stickers-ins-table .st-id{white-space:nowrap}
.stickers-ins-table .st-good b{font-weight:600}
.stickers-ins-table .st-log{max-width:32rem;line-height:1.45;font-size:0.8rem}
.stickers-ins-table .st-log-label{opacity:0.85}
.stickers-ins-table .st-log .user-badge{vertical-align:baseline;margin-right:0.15rem}
.stickers-ins-table .st-flags{font-size:0.8rem}
.stickers-ins-table tfoot .st-totals td{border-top:2px solid var(--border,#ccc);background:var(--bg-muted,rgba(0,0,0,.03));font-size:0.88rem;padding:0.45rem}
.st-exp--ok{color:var(--ok,#2a7)}
.st-exp--warn{color:#a60}
.st-exp--bad{color:var(--danger,#c33);font-weight:600}
</style>
<script>
(function () {
  var form = document.querySelector('form.filters--dense');
  if (!form) return;
  var good = form.querySelector('input[name="good_q"]');
  var hid = form.querySelector('input[name="id_good"]');
  var list = document.getElementById('stickers-materials');
  if (!good || !hid || !list) return;
  good.addEventListener('input', function () {
    // Clear pinned id until server re-resolves (or exact datalist pick).
    var v = good.value.trim();
    var hit = null;
    for (var i = 0; i < list.options.length; i++) {
      if (list.options[i].value === v) { hit = list.options[i]; break; }
    }
    hid.value = hit && hit.getAttribute('data-id') ? hit.getAttribute('data-id') : '';
  });
})();
</script>
