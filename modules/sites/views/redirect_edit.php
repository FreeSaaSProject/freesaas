<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= $isNew ? te('sites.redirects.edit.heading_new') : te('sites.redirects.edit.heading', ['id' => (int)($row['id'] ?? 0)]) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php
/** @var array<int,string> $hostOptions */
$hostOptions = $hostOptions ?? [];
?>
<form method="post" class="form-grid">
  <?php if ($hostOptions !== []): ?>
  <label><?= te('sites.redirects.field.site_host_id') ?>
    <select name="site_host_id">
      <?php foreach ($hostOptions as $hid => $hlabel): ?>
        <option value="<?= (int)$hid ?>"<?= (int)($row['site_host_id'] ?? 0) === (int)$hid ? ' selected' : '' ?>><?= e($hlabel) ?></option>
      <?php endforeach; ?>
    </select>
    <span class="field-hint muted"><?= te('sites.redirects.field.site_host_id_hint') ?></span>
  </label>
  <?php endif; ?>
  <label><?= te('sites.redirects.field.url_old') ?>
    <input name="url_old" maxlength="255" value="<?= e((string)($row['url_old'] ?? '')) ?>" required>
  </label>
  <label><?= te('sites.redirects.field.url_new') ?>
    <input name="url_new" maxlength="255" value="<?= e((string)($row['url_new'] ?? '')) ?>" required>
  </label>
  <label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>> <?= te('sites.redirects.field.enabled') ?></label>
  <?php if (!$isNew && (int)($row['dt_create'] ?? 0) > 0): ?>
    <p class="muted"><?= te('sites.redirects.field.dt_create') ?>: <?= e(date('d.m.Y H:i', (int)$row['dt_create'])) ?></p>
  <?php endif; ?>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/sites/redirects"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (empty($isNew) && empty($is_new)): ?>
<?= EntityDelete::form('sites_redirects', (int)($row['id'] ?? $item['id'] ?? 0)) ?>
<?php endif; ?>

