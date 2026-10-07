<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= $isNew ? te('likespa.redirects.edit.heading_new') : te('likespa.redirects.edit.heading', ['id' => (int)($row['id'] ?? 0)]) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <label><?= te('likespa.redirects.field.url_old') ?>
    <input name="url_old" maxlength="255" value="<?= e((string)($row['url_old'] ?? '')) ?>" required>
  </label>
  <label><?= te('likespa.redirects.field.url_new') ?>
    <input name="url_new" maxlength="255" value="<?= e((string)($row['url_new'] ?? '')) ?>" required>
  </label>
  <label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>> <?= te('likespa.redirects.field.enabled') ?></label>
  <?php if (!$isNew && (int)($row['dt_create'] ?? 0) > 0): ?>
    <p class="muted"><?= te('likespa.redirects.field.dt_create') ?>: <?= e(date('d.m.Y H:i', (int)$row['dt_create'])) ?></p>
  <?php endif; ?>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/likespa/redirects"><?= te('core.common.cancel') ?></a>
  </div>
</form>
