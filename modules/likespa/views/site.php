<?php
declare(strict_types=1);
/** @var array<string,mixed> $row */
/** @var string $mothersite */
/** @var bool $hasKeywords */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <p class="lede"><?= te('likespa.site.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <label><?= te('likespa.site.field_name') ?>
    <input name="name" value="<?= e((string)($row['name'] ?? '')) ?>">
  </label>
  <label><?= te('likespa.site.field_title') ?>
    <input name="title" value="<?= e((string)($row['title'] ?? '')) ?>">
  </label>
  <?php if (!empty($hasKeywords)): ?>
  <label><?= te('likespa.site.field_keywords') ?>
    <input name="keywords" value="<?= e((string)($row['keywords'] ?? '')) ?>">
  </label>
  <?php endif; ?>
  <label><?= te('likespa.site.field_tel') ?>
    <input name="tel" value="<?= e((string)($row['tel'] ?? '')) ?>">
  </label>
  <label><?= te('likespa.site.field_mothersite') ?>
    <input name="mothersite" value="<?= e((string)$mothersite) ?>" placeholder="likespa.ru">
    <span class="field-hint muted"><?= te('likespa.site.field_mothersite_hint') ?></span>
  </label>
  <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
</form>
