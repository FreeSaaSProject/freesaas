<?php
/** @var bool $canEdit */
/** @var string $error */
/** @var string $admin_host */
/** @var string $preview */
/** @var array<string,mixed>|null $default_host */
?>
<section class="page-head">
  <h1><?= te('sites.settings.title') ?></h1>
  <p class="muted"><?= te('sites.settings.role_hint') ?></p>
</section>

<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<p class="form-hint">
  <?= te('sites.settings.hosts_hint') ?>
  <a href="/sites/hosts"><?= te('sites.menu.hosts') ?></a>
</p>
<?php if ($default_host): ?>
  <p class="muted">
    <?= te('sites.settings.default_host') ?>:
    <code><?= e((string)$default_host['host']) ?></code>
    · <?= te('sites.settings.field_theme') ?>: <code><?= e((string)$default_host['theme']) ?></code>
  </p>
<?php endif; ?>
<?php if ($preview !== ''): ?>
  <p><a class="btn" href="<?= e($preview) ?>" target="_blank" rel="noopener"><?= te('sites.settings.preview') ?></a></p>
<?php endif; ?>

<form method="post" class="form-grid">
  <label><?= te('sites.settings.field_admin_host') ?>
    <input name="admin_host" value="<?= e($admin_host) ?>" placeholder="admin.example.com" <?= $canEdit ? '' : 'disabled' ?>>
    <span class="field-hint muted"><?= te('sites.settings.field_admin_host_hint') ?></span>
  </label>
  <?php if ($canEdit): ?>
    <p><button type="submit" class="btn-primary"><?= te('sites.settings.save') ?></button></p>
  <?php endif; ?>
</form>
