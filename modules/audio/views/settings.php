<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,string> $cfg */
/** @var string $extraHtml */
$extraHtml = (string)($extraHtml ?? '');
?>
<section class="page-head">
  <p class="lede"><?= t('audio.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <?php require __DIR__ . '/_settings_storage.php'; ?>
  <?= $extraHtml ?>
  <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
</form>
