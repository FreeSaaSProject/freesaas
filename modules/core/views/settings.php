<?php
/** @var ?array $row */
/** @var string $message */
/** @var string $error */
/** @var ?string $logoUrl */
/** @var bool $hasLogo */
/** @var string $extraHtml */
$logoUrl = isset($logoUrl) ? $logoUrl : null;
$hasLogo = !empty($hasLogo);
$extraHtml = (string)($extraHtml ?? '');
?>
<section class="page-head">
  <p class="lede"><?= t('core.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if (!$row): ?>
  <div class="flash flash-warn"><?= te('core.settings.empty') ?></div>
<?php else: ?>
<form method="post" class="form-grid" enctype="multipart/form-data">
  <label><?= te('core.settings.field_title') ?>
    <input name="title" value="<?= e((string)($row['title'] ?? '')) ?>">
  </label>
  <label><?= te('core.settings.field_company') ?>
    <input name="company" value="<?= e((string)($row['company'] ?? '')) ?>">
    <span class="field-hint muted"><?= te('core.settings.field_company_hint') ?></span>
  </label>
  <label><?= te('core.settings.field_name') ?>
    <input name="name" value="<?= e((string)($row['name'] ?? '')) ?>">
  </label>
  <label><?= te('core.settings.field_tel') ?>
    <input name="tel" value="<?= e((string)($row['tel'] ?? '')) ?>">
  </label>

  <fieldset class="form-fieldset logo-fieldset">
    <legend><?= te('core.settings.logo.title') ?></legend>
    <p class="muted"><?= te('core.settings.logo.hint') ?></p>
    <?php if ($hasLogo && $logoUrl): ?>
      <div class="logo-preview">
        <img src="<?= e($logoUrl) ?>" alt="<?= te('core.settings.logo.preview_alt') ?>">
      </div>
      <label class="logo-remove">
        <input type="checkbox" name="logo_remove" value="1">
        <?= te('core.settings.logo.remove') ?>
      </label>
    <?php endif; ?>
    <label><?= te('core.settings.logo.file') ?>
      <input type="file" name="logo" accept="image/png,.png">
    </label>
  </fieldset>

  <?= $extraHtml ?? '' ?>
  <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
</form>
<?php endif; ?>
