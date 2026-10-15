<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= $isNew ? te('clients.tag.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('clients.tag.name') ?>
    <input name="name" maxlength="200" value="<?= e((string)($row['name'] ?? '')) ?>" required>
  </label>
  <label class="check">
    <input type="checkbox" name="enabled" value="1"<?= !isset($row['enabled']) || !empty($row['enabled']) ? ' checked' : '' ?>>
    <?= te('clients.tag.enabled') ?>
  </label>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/client-tags"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (empty($isNew) && empty($is_new)): ?>
<?= EntityDelete::form('client_tags', (int)($row['id'] ?? 0)) ?>
<?php endif; ?>

