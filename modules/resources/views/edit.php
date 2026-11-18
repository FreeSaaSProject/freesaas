<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
$enabled = array_key_exists('enabled', $row) ? !empty($row['enabled']) : true;
?>
<section class="page-head">
  <h1><?= $isNew ? te('resources.edit.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form">
  <fieldset>
    <legend><?= te('resources.edit.sec_main') ?></legend>
    <label><?= te('resources.col.name') ?>
      <input name="name" maxlength="255" value="<?= e((string)($row['name'] ?? '')) ?>" required>
    </label>
    <label><?= te('resources.col.notes') ?>
      <input name="notes" maxlength="255" value="<?= e((string)($row['notes'] ?? '')) ?>">
    </label>
    <label><?= te('resources.col.qty') ?>
      <input type="number" min="1" max="9999" name="qty" value="<?= e((string)($row['qty'] ?? '1')) ?>">
      <span class="muted"><?= te('resources.edit.qty_hint') ?></span>
    </label>
    <label class="check">
      <input type="checkbox" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?>>
      <?= te('resources.col.enabled') ?>
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/resources"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (empty($isNew) && empty($is_new)): ?>
<?= EntityDelete::form('resources', (int)($row['id'] ?? 0)) ?>
<?php endif; ?>
