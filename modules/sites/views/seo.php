<?php
declare(strict_types=1);
/** @var array<string,mixed> $row */
/** @var bool $hasKeywords */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <p class="lede"><?= te('sites.seo.lede') ?></p>
  <p class="muted"><?= te('sites.seo.hosts_hint') ?> <a href="/sites/hosts"><?= te('sites.menu.hosts') ?></a></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <label><?= te('sites.seo.field_name') ?>
    <input name="name" value="<?= e((string)($row['name'] ?? '')) ?>">
    <span class="field-hint muted"><?= te('sites.seo.field_name_hint') ?></span>
  </label>
  <label><?= te('sites.seo.field_title') ?>
    <input name="title" value="<?= e((string)($row['title'] ?? '')) ?>">
  </label>
  <?php if (!empty($hasKeywords)): ?>
  <label><?= te('sites.seo.field_keywords') ?>
    <input name="keywords" value="<?= e((string)($row['keywords'] ?? '')) ?>">
  </label>
  <?php endif; ?>
  <label><?= te('sites.seo.field_tel') ?>
    <input name="tel" value="<?= e((string)($row['tel'] ?? '')) ?>">
    <span class="field-hint muted"><?= te('sites.seo.field_tel_hint') ?></span>
  </label>
  <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
</form>
