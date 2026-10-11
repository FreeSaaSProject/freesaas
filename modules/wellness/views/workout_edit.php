<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= $isNew ? te('wellness.workouts.edit_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('wellness.col.name') ?>
    <input name="name" maxlength="255" value="<?= e((string)($row['name'] ?? '')) ?>" required>
  </label>
  <label><?= te('wellness.col.dt_start') ?>
    <input type="datetime-local" name="dt_start" value="<?= e(WellnessUi::datetimeLocal(isset($row['dt_start']) ? (int)$row['dt_start'] : null)) ?>" required>
  </label>
  <label><?= te('wellness.col.dt_end') ?>
    <input type="datetime-local" name="dt_end" value="<?= e(WellnessUi::datetimeLocal(isset($row['dt_end']) ? (int)$row['dt_end'] : (isset($row['dt_start']) ? (int)$row['dt_start'] : null))) ?>">
  </label>
  <label><?= te('wellness.col.ccals') ?>
    <input type="number" name="ccals" min="0" value="<?= e((string)($row['ccals'] ?? 0)) ?>">
  </label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/wellness/workouts"><?= te('core.common.cancel') ?></a>
  </div>
</form>
