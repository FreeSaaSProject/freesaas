<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
/** @var bool $canEdit */
?>
<section class="page-head">
  <h1><?= $isNew ? te('terminals.bracelets.edit.heading_new') : ('#' . (int)($row['id'] ?? 0)) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('terminals.bracelets.col.uid') ?>
    <input name="uid" maxlength="20" value="<?= e((string)($row['uid'] ?? '')) ?>"<?= $canEdit ? '' : ' disabled' ?>>
  </label>
  <label><?= te('terminals.bracelets.col.uid2') ?>
    <input name="uid2" maxlength="20" value="<?= e((string)($row['uid2'] ?? '')) ?>"<?= $canEdit ? '' : ' disabled' ?>>
  </label>
  <label><?= te('terminals.bracelets.col.num') ?>
    <input type="number" name="num" min="0" value="<?= (int)($row['num'] ?? 0) ?>"<?= $canEdit ? '' : ' disabled' ?>>
  </label>
  <div class="page-actions">
    <?php if ($canEdit): ?>
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <?php endif; ?>
    <a class="btn-secondary" href="/terminals/bracelets"><?= te('core.common.cancel') ?></a>
  </div>
</form>
