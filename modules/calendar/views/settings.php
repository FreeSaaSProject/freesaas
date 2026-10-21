<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var ?array $row */
/** @var bool $showHours */
/** @var string $eventMode */
/** @var int $compactChipMinutes */
/** @var string $extraHtml */
$extraHtml = (string)($extraHtml ?? '');
$eventMode = (string)($eventMode ?? 'blocks');
$compactChipMinutes = (int)($compactChipMinutes ?? CalendarConfig::compactChipMinutes());
?>
<section class="page-head">
  <p class="lede"><?= t('calendar.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <?php require __DIR__ . '/_settings_hours.php'; ?>
  <?= $extraHtml ?>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
  </div>
</form>
