<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var list<array<string,mixed>> $symptoms */
/** @var list<array<string,mixed>> $medicines */
/** @var string $message */
/** @var string $error */
$symptomId = (int)($row['symptom_id'] ?? 0);
$medicineId = (int)($row['medicine_id'] ?? 0);
?>
<section class="page-head">
  <h1><?= $isNew ? te('wellness.health_diary.edit_new') : ('#' . (int)($row['id'] ?? 0)) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('wellness.col.dt') ?>
    <input type="datetime-local" name="dt" value="<?= e(WellnessUi::datetimeLocal(isset($row['dt']) ? (int)$row['dt'] : null)) ?>" required>
  </label>
  <label><?= te('wellness.col.symptom') ?>
    <select name="symptom_id">
      <option value="0">—</option>
      <?php foreach ($symptoms as $s): ?>
        <option value="<?= (int)$s['id'] ?>"<?= $symptomId === (int)$s['id'] ? ' selected' : '' ?>><?= e((string)$s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('wellness.col.symptom') ?> (<?= te('core.common.add') ?>)
    <input name="symptom_name" value="<?= e((string)($row['symptom_name'] ?? '')) ?>" placeholder="<?= te('wellness.symptoms.edit_new') ?>">
  </label>
  <label><?= te('wellness.col.medicine') ?>
    <select name="medicine_id">
      <option value="0">—</option>
      <?php foreach ($medicines as $m): ?>
        <option value="<?= (int)$m['id'] ?>"<?= $medicineId === (int)$m['id'] ? ' selected' : '' ?>><?= e((string)$m['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('wellness.col.medicine') ?> (<?= te('core.common.add') ?>)
    <input name="medicine_name" value="<?= e((string)($row['medicine_name'] ?? '')) ?>" placeholder="<?= te('wellness.medicines.edit_new') ?>">
  </label>
  <label><?= te('wellness.col.level') ?>
    <input type="number" name="level" min="0" max="5" value="<?= e((string)($row['level'] ?? 0)) ?>">
  </label>
  <label><?= te('wellness.col.descr') ?>
    <input name="descr" maxlength="255" value="<?= e((string)($row['descr'] ?? '')) ?>">
  </label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/wellness/health-diary"><?= te('core.common.cancel') ?></a>
  </div>
</form>
