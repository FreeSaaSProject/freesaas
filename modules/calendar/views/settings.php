<?php
/** @var string $message */
/** @var string $error */
/** @var ?array $row */
/** @var bool $showHours */
/** @var bool $manualCreate */
?>
<section class="page-head">
  <p class="lede"><?= t('calendar.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <?php require __DIR__ . '/_settings_hours.php'; ?>
  <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
</form>
