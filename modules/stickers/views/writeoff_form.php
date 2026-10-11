<?php
declare(strict_types=1);
/** @var ?array<string,mixed> $row */
/** @var int $stickerId */
/** @var array<int,string> $products */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head"><h1><?= te('stickers.writeoff.form_title') ?></h1></section>
<p class="muted"><?= te('stickers.writeoff.hint_free_act') ?></p>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="get" class="filters" action="/stickers/writeoff">
  <label><?= te('stickers.col.sticker') ?>
    <input type="number" min="1" name="id_sticker" value="<?= $stickerId > 0 ? $stickerId : '' ?>" required>
  </label>
  <button type="submit" class="btn-secondary"><?= te('core.common.edit') ?></button>
</form>

<?php if ($row): ?>
  <p>
    <strong><?= e($products[(int)$row['id_good']] ?? ('#' . (int)$row['id_good'])) ?></strong>
    · <?= te('stickers.col.left') ?>: <strong><?= e((string)StickerInsRepository::remaining($row)) ?></strong>
    · PIN <code><?= e((string)$row['pin']) ?></code>
    · <?= te('stickers.col.stock') ?> #<?= (int)$row['id_stock'] ?>
    · <?= te('stickers.col.act') ?> <a href="/stickers/receipts/<?= (int)$row['id_act'] ?>/edit">П-<?= (int)$row['id_act'] ?></a>
  </p>
  <p class="muted"><?= te('stickers.writeoff.dest_explain') ?></p>
  <form method="post" class="form-grid form-wide">
    <?= Csrf::field() ?>
    <input type="hidden" name="id_sticker" value="<?= (int)$row['id'] ?>">
    <label><?= te('stickers.writeoff.amount') ?>
      <input name="amount" value="<?= e((string)((float)$row['qty_qtyu_rate'] == 1.0 ? '1' : '')) ?>" required>
    </label>
    <label><?= te('stickers.col.descr') ?>
      <input name="descr" maxlength="255" value="">
    </label>
    <div class="page-actions">
      <button class="btn-primary" type="submit"><?= te('stickers.writeoff.submit') ?></button>
      <a class="btn-secondary" href="/stickers/ins/<?= (int)$row['id'] ?>/edit"><?= te('core.common.cancel') ?></a>
      <a class="btn-secondary" href="/stickers/outs"><?= te('stickers.menu.outs') ?></a>
      <a class="btn-secondary" href="/stickers/writeoffs"><?= te('stickers.menu.writeoffs') ?></a>
    </div>
  </form>
<?php elseif ($stickerId > 0): ?>
  <div class="flash flash-warn"><?= te('stickers.err.sticker_not_found', ['id' => $stickerId]) ?></div>
<?php endif; ?>
