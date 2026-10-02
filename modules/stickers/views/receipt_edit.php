<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $stocks */
/** @var array<int,string> $suppliers */
/** @var list<array<string,mixed>> $stickers */
/** @var array<int,string> $products */
/** @var bool $canWriteoff */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head"><h1><?= e((string)$title) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <fieldset>
    <label><?= te('stickers.col.descr') ?>
      <input name="descr" maxlength="255" value="<?= e((string)($row['descr'] ?? '')) ?>" required>
    </label>
    <label><?= te('stickers.col.stock') ?>
      <select name="id_stock" required>
        <option value="">—</option>
        <?php foreach ($stocks as $sid => $sname): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['id_stock'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('stickers.col.supplier') ?>
      <select name="id_supplier">
        <option value="0">—</option>
        <?php foreach ($suppliers as $sid => $sname): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['id_supplier'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="check"><input type="checkbox" name="is_signed" value="1"<?= !empty($row['is_signed']) ? ' checked' : '' ?>> <?= te('stickers.col.signed') ?></label>
    <label class="check"><input type="checkbox" name="is_sticked" value="1"<?= !empty($row['is_sticked']) ? ' checked' : '' ?>> <?= te('stickers.col.sticked') ?></label>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/stickers/receipts"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (!$isNew): ?>
  <h2><?= te('stickers.receipt.stickers') ?></h2>
  <p><a class="btn-secondary" href="/stickers/ins/new?id_act=<?= (int)$row['id'] ?>"><?= te('stickers.receipt.add_sticker') ?></a></p>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th>ID</th><th><?= te('stickers.col.good') ?></th><th><?= te('stickers.col.price') ?></th>
      <th><?= te('stickers.col.left') ?></th><th><?= te('stickers.col.pin') ?></th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($stickers as $s): ?>
      <tr>
        <td><a href="/stickers/ins/<?= (int)$s['id'] ?>/edit"><?= (int)$s['id'] ?></a></td>
        <td><?= e($products[(int)$s['id_good']] ?? ('#' . (int)$s['id_good'])) ?></td>
        <td><?= e((string)$s['price']) ?></td>
        <td><?= e((string)round((float)$s['qtyu_in'] - (float)$s['qtyu_out'], 3)) ?></td>
        <td><code><?= e((string)$s['pin']) ?></code></td>
        <td><?php if ($canWriteoff): ?><a href="/stickers/writeoff?id_sticker=<?= (int)$s['id'] ?>"><?= te('stickers.writeoff.submit') ?></a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$stickers): ?><tr><td colspan="6" class="muted"><?= te('stickers.list.empty') ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
<?php endif; ?>
