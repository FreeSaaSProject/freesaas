<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $stocks */
/** @var list<array<string,mixed>> $outs */
/** @var array<int,string> $products */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head"><h1><?= e((string)$title) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <fieldset>
    <label><?= te('stickers.col.stock') ?>
      <select name="id_stock" required>
        <option value="">—</option>
        <?php foreach ($stocks as $sid => $sname): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['id_stock'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('stickers.col.descr') ?>
      <textarea name="descr" rows="3"><?= e((string)($row['descr'] ?? '')) ?></textarea>
    </label>
    <input type="hidden" name="id_event" value="<?= (int)($row['id_event'] ?? 0) ?>">
    <input type="hidden" name="id_proc" value="<?= (int)($row['id_proc'] ?? 0) ?>">
    <input type="hidden" name="id_good" value="<?= (int)($row['id_good'] ?? 0) ?>">
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/stickers/writeoffs"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!$isNew): ?>
  <?php
    $outQty = 0.0;
    $outSum = 0.0;
    foreach ($outs as $o) {
        $outQty += (float)($o['qtyu'] ?? 0);
        $outSum += (float)($o['summ'] ?? 0);
    }
    $outQty = round($outQty, 3);
    $outSum = round($outSum, 2);
  ?>
  <h2><?= te('stickers.menu.outs') ?></h2>
  <div class="table-wrap"><table class="data data--compact">
    <thead><tr>
      <th>ID</th>
      <th><?= te('stickers.col.sticker') ?></th>
      <th><?= te('stickers.col.good') ?></th>
      <th><?= te('stickers.col.user') ?></th>
      <th><?= te('stickers.col.qty') ?></th>
      <th><?= te('stickers.col.summ') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($outs as $o): ?>
      <tr>
        <td><?= (int)$o['id'] ?></td>
        <td><a href="/stickers/ins/<?= (int)$o['id_sticker'] ?>/edit"><?= (int)$o['id_sticker'] ?></a></td>
        <td><?= e($products[(int)$o['id_good']] ?? ('#' . (int)$o['id_good'])) ?></td>
        <td><?= class_exists('UsersRepository', false) ? UsersRepository::badgeHtml((int)($o['id_user'] ?? 0), false, true) : e((string)($o['id_user'] ?? '')) ?></td>
        <td><?= e((string)$o['qtyu']) ?></td>
        <td><?= e((string)($o['summ'] ?? '0')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$outs): ?><tr><td colspan="6" class="muted"><?= te('stickers.list.empty') ?></td></tr><?php endif; ?>
      <tr class="st-totals">
        <td colspan="3"><b><?= te('stickers.list.totals') ?></b>
          <span class="muted"><?= te('stickers.list.totals_rows', ['n' => count($outs)]) ?></span>
        </td>
        <td></td>
        <td><b><?= e((string)$outQty) ?></b></td>
        <td><b><?= e((string)$outSum) ?></b></td>
      </tr>
    </tbody>
  </table></div>
  <style>.st-totals td{border-top:2px solid var(--border,#ccc);background:var(--bg-muted,rgba(0,0,0,.04))}</style>
<?php endif; ?>
