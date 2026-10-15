<?php
declare(strict_types=1);
/** @var ?array $row */
/** @var bool $isNew */
/** @var string $error */
/** @var string $path */
?>
<?php if ($error !== ''): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="form">
  <label><?= te('audio_dicts.col.name') ?>
    <input name="name" required value="<?= e((string)($row['name'] ?? '')) ?>">
  </label>
  <label class="logo-remove">
    <input type="checkbox" name="enabled" value="1"<?= ($isNew || !empty($row['enabled'])) ? ' checked' : '' ?>>
    <?= te('audio_dicts.col.enabled') ?>
  </label>
  <div class="form-actions">
    <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
    <a href="<?= e($path) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (empty($isNew)): ?>
<?= EntityDelete::form((string)($listId ?? 'audio_dicts'), (int)($id ?? $row['id'] ?? 0)) ?>
<?php endif; ?>

