<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= $isNew ? te('units.edit.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('units.col.name') ?>
    <input name="name" maxlength="10" value="<?= e((string)($row['name'] ?? '')) ?>" required>
  </label>
  <label class="check">
    <input type="checkbox" name="weight_req" value="1"<?= !empty($row['weight_req']) ? ' checked' : '' ?>>
    <?= te('units.col.weight_req') ?>
  </label>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/units"><?= te('core.common.cancel') ?></a>
  </div>
</form>
