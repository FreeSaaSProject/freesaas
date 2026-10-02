<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= $isNew ? te('payments.types.edit_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('payments.types.col.name') ?>
    <input name="name" maxlength="5" value="<?= e((string)($row['name'] ?? '')) ?>" required>
  </label>
  <label><?= te('payments.types.col.descr') ?>
    <input name="descr" value="<?= e((string)($row['descr'] ?? '')) ?>">
  </label>
  <label><?= te('payments.types.col.color') ?>
    <input name="color" maxlength="10" value="<?= e((string)($row['color'] ?? '')) ?>" placeholder="#RRGGBB">
  </label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/payment-types"><?= te('core.common.cancel') ?></a>
  </div>
</form>
