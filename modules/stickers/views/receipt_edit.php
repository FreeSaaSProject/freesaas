<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $stocks */
/** @var array<int,string> $suppliers */
/** @var list<array<string,mixed>> $stickers */
/** @var array{price:float,qty:float,summ:float,qtyu_in:float,qtyu_out:float,qtyu_left:float,unit_price_sum:float,summ_left:float} $sums */
/** @var int $sumsTotal */
/** @var list<array{id:int,summ:float,type_id:int,account_id:int}> $dpsMoves */
/** @var bool $ledgerOn */
/** @var array<int,string> $products */
/** @var array<int,array{unit:string,unitu:string}> $productUnits */
/** @var bool $canWriteoff */
/** @var string $message */
/** @var string $error */
$productUnits = is_array($productUnits ?? null) ? $productUnits : [];
$sums = is_array($sums ?? null) ? $sums : StickerInsRepository::emptySums();
$sumsTotal = (int)($sumsTotal ?? 0);
$dpsMoves = is_array($dpsMoves ?? null) ? $dpsMoves : [];
$ledgerOn = !empty($ledgerOn);
?>
<section class="page-head"><h1><?= e((string)$title) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<?php if (!$isNew): ?>
  <p class="muted">
    <?= te('stickers.col.summ') ?>: <b><?= e((string)($row['summ'] ?? $sums['summ'] ?? 0)) ?></b>
    · <?= te('stickers.col.dps') ?>:
    <?php if ($dpsMoves !== []): ?>
      <?php foreach ($dpsMoves as $m): ?>
        <a href="/ledger/moves/<?= (int)$m['id'] ?>/edit">П-<?= (int)$m['id'] ?></a>
        <span class="muted">(<?= e((string)$m['summ']) ?>)</span>
      <?php endforeach; ?>
    <?php elseif ($ledgerOn): ?>
      <b class="st-dps-miss"><?= te('stickers.dps.none') ?></b>
      <span class="muted"><?= te('stickers.receipts.dps_hint') ?></span>
    <?php else: ?>
      —
    <?php endif; ?>
  </p>
<?php endif; ?>
<?php
  $lockFields = !$isNew;
  $ro = $lockFields ? ' readonly' : '';
  $dis = $lockFields ? ' disabled' : '';
?>
<?php if ($isNew): ?>
<form method="post" class="form-grid form-wide">
<?php else: ?>
<div class="form-grid form-wide">
<?php endif; ?>
  <fieldset>
    <label><?= te('stickers.col.descr') ?>
      <input name="descr" maxlength="255" value="<?= e((string)($row['descr'] ?? '')) ?>" required<?= $ro ?>>
    </label>
    <label><?= te('stickers.col.stock') ?>
      <select name="id_stock"<?= empty($row['id_stock']) ? '' : ' required' ?><?= $dis ?>>
        <option value="">—</option>
        <?php foreach ($stocks as $sid => $sname): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['id_stock'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('stickers.col.supplier') ?>
      <select name="id_supplier"<?= $dis ?>>
        <option value="0">—</option>
        <?php foreach ($suppliers as $sid => $sname): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['id_supplier'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="check"><input type="checkbox" name="is_signed" value="1"<?= !empty($row['is_signed']) ? ' checked' : '' ?><?= $dis ?>> <?= te('stickers.col.signed') ?></label>
    <label class="check"><input type="checkbox" name="is_sticked" value="1"<?= !empty($row['is_sticked']) ? ' checked' : '' ?><?= $dis ?>> <?= te('stickers.col.sticked') ?></label>
  </fieldset>
  <div class="page-actions">
    <?php if ($isNew): ?>
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <?php endif; ?>
    <a class="btn-secondary" href="/stickers/receipts"><?= te('core.common.cancel') ?></a>
  </div>
<?php if ($isNew): ?>
</form>
<?php else: ?>
</div>
<?php endif; ?>

<?php if (!$isNew): ?>
  <h2><?= te('stickers.receipt.stickers') ?></h2>
  <p class="muted"><?= te('stickers.receipt.grouped_hint') ?></p>
  <p><a class="btn-secondary" href="/stickers/ins/new?id_act=<?= (int)$row['id'] ?>"><?= te('stickers.receipt.add_sticker') ?></a>
     <a class="btn-secondary" href="/stickers/ins?id_act=<?= (int)$row['id'] ?>"><?= te('stickers.receipt.open_all') ?></a></p>
  <div class="table-wrap"><table class="data data--compact">
    <thead><tr>
      <th><?= te('stickers.col.good') ?></th>
      <th><?= te('stickers.col.qty') ?></th>
      <th><?= te('stickers.col.price') ?></th>
      <th title="<?= te('stickers.col.summ') ?>">Σ</th>
      <th><?= te('stickers.col.rate') ?></th>
      <th><?= te('stickers.col.expire') ?></th>
      <th><?= te('stickers.col.left') ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php
      $linesSum = 0.0;
      $linesQty = 0;
    ?>
    <?php foreach ($stickers as $s): ?>
      <?php
        $twins = max(1, (int)($s['twin_count'] ?? $s['qty'] ?? 1));
        $qty = max($twins, (int)($s['qty'] ?? $twins));
        $rate = (float)($s['qty_qtyu_rate'] ?? 1);
        if ($rate <= 0) {
            $rate = 1.0;
        }
        $price = (float)($s['price'] ?? 0);
        // Act SUMM = Σ PRICE per sticker row → line subtotal = price × twin count.
        $lineSum = round($price * $twins, 2);
        $linesSum += $lineSum;
        $linesQty += $twins;
        $left = round((float)$s['qtyu_in'] - (float)$s['qtyu_out'], 3);
        $pu = $productUnits[(int)$s['id_good']] ?? ['unit' => '', 'unitu' => ''];
        $unitu = $pu['unitu'] !== '' ? $pu['unitu'] : t('stickers.col.ue');
        $unit = $pu['unit'] !== '' ? $pu['unit'] : t('stickers.col.e');
        $exp = (int)($s['dt_expire'] ?? 0);
        $descr = trim((string)($s['descr'] ?? ''));
      ?>
      <tr>
        <td>
          <b><?= e($products[(int)$s['id_good']] ?? ('#' . (int)$s['id_good'])) ?></b>
          <?php if ($descr !== ''): ?><div class="muted"><?= e($descr) ?></div><?php endif; ?>
          <div class="muted">#<?= (int)$s['id'] ?></div>
        </td>
        <td><?= (int)$qty ?> <?= e($unit) ?></td>
        <td><?= e((string)$price) ?></td>
        <td><b><?= e((string)$lineSum) ?></b></td>
        <td><?= e((string)$rate) ?> <?= e($unitu) ?>/<?= e($unit) ?></td>
        <td><?= $exp > 0 ? e(date('d.m.Y', $exp)) : '—' ?></td>
        <td><b><?= e((string)$left) ?></b> <?= e($unitu) ?>
          <div class="muted"><?= te('stickers.col.qtyu_in') ?> <?= e((string)$s['qtyu_in']) ?> / <?= te('stickers.col.qtyu_out') ?> <?= e((string)$s['qtyu_out']) ?></div>
        </td>
        <td>
          <a href="/stickers/ins?id_act=<?= (int)$row['id'] ?>&id_good=<?= (int)$s['id_good'] ?>"><?= te('stickers.receipt.show_twins') ?></a>
          <?php if ($canWriteoff && $left > 0): ?>
            · <a href="/stickers/writeoff?id_sticker=<?= (int)$s['id'] ?>" title="<?= te('stickers.writeoff.hint_free_act') ?>"><?= te('stickers.writeoff.submit') ?></a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$stickers): ?><tr><td colspan="8" class="muted"><?= te('stickers.list.empty') ?></td></tr><?php endif; ?>
      <tr class="st-totals">
        <td><b><?= te('stickers.list.totals') ?></b>
          <div class="muted"><?= te('stickers.list.totals_rows', ['n' => $sumsTotal]) ?></div>
        </td>
        <td><?= e((string)$linesQty) ?></td>
        <td class="muted"><?= te('stickers.list.totals_unit_avg') ?>:
          <?= e((string)($sumsTotal > 0 ? round((float)$sums['unit_price_sum'] / $sumsTotal, 2) : 0)) ?>
        </td>
        <td><b><?= e((string)round($linesSum, 2)) ?></b></td>
        <td></td>
        <td></td>
        <td>
          <b><?= e((string)$sums['qtyu_left']) ?></b>
          <div class="muted"><?= te('stickers.col.qtyu_in') ?> <?= e((string)$sums['qtyu_in']) ?>
            / <?= te('stickers.col.qtyu_out') ?> <?= e((string)$sums['qtyu_out']) ?></div>
          <div class="muted"><?= e((string)$sums['summ_left']) ?></div>
        </td>
        <td></td>
      </tr>
    </tbody>
  </table></div>
  <style>
  .st-totals td{border-top:2px solid var(--border,#ccc);background:var(--bg-muted,rgba(0,0,0,.04));font-size:0.9rem;padding:0.5rem 0.45rem}
  .st-dps-miss{color:var(--danger,#c33)}
  </style>
<?php endif; ?>
