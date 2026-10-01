<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= $isNew ? te('cabinets.edit.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form">
  <fieldset>
    <legend><?= te('cabinets.edit.sec_main') ?></legend>
    <label><?= te('cabinets.col.name') ?>
      <input name="name" maxlength="255" value="<?= e((string)($row['name'] ?? '')) ?>" required>
    </label>
    <label><?= te('cabinets.col.descr') ?>
      <input name="descr" maxlength="255" value="<?= e((string)($row['descr'] ?? '')) ?>">
    </label>
    <label><?= te('cabinets.col.limit_min') ?>
      <input type="number" min="0" name="limit_min" value="<?= e((string)($row['limit_min'] ?? '0')) ?>">
    </label>
    <label><?= te('cabinets.col.limit_max') ?>
      <input type="number" min="0" name="limit_max" value="<?= e((string)($row['limit_max'] ?? '0')) ?>">
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/cabinets"><?= te('core.common.cancel') ?></a>
  </div>
</form>
