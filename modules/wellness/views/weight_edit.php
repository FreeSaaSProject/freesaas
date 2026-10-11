<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= $isNew ? te('wellness.weight.edit_new') : te('wellness.weight.edit') ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('wellness.col.dt') ?>
    <input type="date" name="dt" value="<?= e(WellnessUi::dateOnly(isset($row['dt']) ? (int)$row['dt'] : null)) ?>" required>
  </label>
  <label><?= te('wellness.col.weight_kg') ?>
    <input name="weight" value="<?= e((string)($row['weight'] ?? '')) ?>" required>
  </label>
  <label><?= te('wellness.col.fat') ?>
    <input name="fat" value="<?= e((string)($row['fat'] ?? 0)) ?>">
  </label>
  <label><?= te('wellness.col.water') ?>
    <input name="water" value="<?= e((string)($row['water'] ?? 0)) ?>">
  </label>
  <label><?= te('wellness.col.muscles') ?>
    <input name="muscles" value="<?= e((string)($row['muscles'] ?? 0)) ?>">
  </label>
  <label><?= te('wellness.col.waist') ?>
    <input type="number" name="waist" min="0" value="<?= e((string)($row['waist'] ?? 0)) ?>">
  </label>
  <label><?= te('wellness.col.hips') ?>
    <input type="number" name="hips" min="0" value="<?= e((string)($row['hips'] ?? 0)) ?>">
  </label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/wellness/weight"><?= te('core.common.cancel') ?></a>
  </div>
</form>
