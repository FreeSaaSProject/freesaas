<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= $isNew ? te('wellness.doctor_rx.edit_new') : te('wellness.doctor_rx.edit') ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('wellness.col.dt') ?>
    <input type="date" name="dt" value="<?= e(WellnessUi::dateOnly(isset($row['dt']) ? (int)$row['dt'] : null)) ?>" required>
  </label>
  <label><?= te('wellness.col.doctor') ?>
    <input name="doctor" maxlength="64" value="<?= e((string)($row['doctor'] ?? '')) ?>">
  </label>
  <label><?= te('wellness.col.data') ?>
    <textarea name="data" rows="8" required><?= e((string)($row['data'] ?? '')) ?></textarea>
  </label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/wellness/doctor-rx"><?= te('core.common.cancel') ?></a>
  </div>
</form>
