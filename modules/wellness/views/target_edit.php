<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= $isNew ? te('wellness.targets.edit_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('wellness.col.name') ?>
    <input name="name" maxlength="255" value="<?= e((string)($row['name'] ?? '')) ?>">
  </label>
  <label><?= te('wellness.col.dt') ?>
    <input type="date" name="dt" value="<?= e(WellnessUi::dateOnly(isset($row['dt']) ? (int)$row['dt'] : null)) ?>" required>
  </label>
  <label><?= te('wellness.col.weight_kg') ?>
    <input name="weight" value="<?= e((string)($row['weight'] ?? 0)) ?>">
  </label>
  <label><?= te('wellness.col.ccals') ?>
    <input type="number" name="ccals" min="0" value="<?= e((string)($row['ccals'] ?? 0)) ?>">
  </label>
  <label><?= te('wellness.col.proteins') ?>
    <input name="proteins" value="<?= e((string)($row['proteins'] ?? 0)) ?>">
  </label>
  <label><?= te('wellness.col.fats') ?>
    <input name="fats" value="<?= e((string)($row['fats'] ?? 0)) ?>">
  </label>
  <label><?= te('wellness.col.carbs') ?>
    <input name="carbs" value="<?= e((string)($row['carbs'] ?? 0)) ?>">
  </label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/wellness/targets"><?= te('core.common.cancel') ?></a>
  </div>
</form>
