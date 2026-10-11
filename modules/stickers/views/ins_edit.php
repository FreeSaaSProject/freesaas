<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var list<array<string,mixed>> $acts */
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
    <label><?= te('stickers.col.act') ?>
      <select name="id_act" required<?= !$isNew ? ' disabled' : '' ?>>
        <option value="">—</option>
        <?php foreach ($acts as $a): ?>
          <option value="<?= (int)$a['id'] ?>"<?= (int)($row['id_act'] ?? 0) === (int)$a['id'] ? ' selected' : '' ?>">
            #<?= (int)$a['id'] ?> — <?= e((string)$a['descr']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (!$isNew): ?><input type="hidden" name="id_act" value="<?= (int)$row['id_act'] ?>"><?php endif; ?>
    </label>
    <label><?= te('stickers.col.good') ?>
      <select name="id_good" required>
        <option value="">—</option>
        <?php foreach ($products as $pid => $pname): ?>
          <option value="<?= (int)$pid ?>"<?= (int)($row['id_good'] ?? 0) === (int)$pid ? ' selected' : '' ?>><?= e($pname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('stickers.col.qty') ?>
      <input type="number" min="1" name="qty" value="<?= e((string)($row['qty'] ?? 1)) ?>"<?= !$isNew ? ' readonly' : '' ?> required>
    </label>
    <label><?= te('stickers.col.rate') ?>
      <input name="qty_qtyu_rate" value="<?= e((string)($row['qty_qtyu_rate'] ?? '1')) ?>" required>
    </label>
    <label><?= te('stickers.col.price') ?>
      <input name="price" value="<?= e((string)($row['price'] ?? '0')) ?>">
    </label>
    <label><?= te('stickers.col.descr') ?>
      <input name="descr" maxlength="255" value="<?= e((string)($row['descr'] ?? '')) ?>">
    </label>
    <label class="check"><input type="checkbox" name="activate" value="1"<?= !empty($row['dt_print']) ? ' checked' : '' ?>> <?= te('stickers.ins.activate') ?></label>
    <?php if (!$isNew): ?>
      <p class="muted"><?= te('stickers.col.pin') ?>: <code><?= e((string)($row['pin'] ?? '')) ?></code>
        · <?= te('stickers.col.left') ?>: <strong><?= e((string)StickerInsRepository::remaining($row)) ?></strong></p>
    <?php endif; ?>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/stickers/ins"><?= te('core.common.cancel') ?></a>
    <?php if ($canWriteoff): ?>
      <a class="btn-secondary" href="/stickers/writeoff?id_sticker=<?= (int)$row['id'] ?>"><?= te('stickers.writeoff.submit') ?></a>
    <?php endif; ?>
  </div>
</form>
