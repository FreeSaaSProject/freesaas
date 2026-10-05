<?php
/**
 * Calendar module settings form sections.
 *
 * @var ?array $row
 * @var bool $showHours
 * @var bool $manualCreate
 */
$row = is_array($row ?? null) ? $row : [];
$showHours = !empty($showHours);
$manualCreate = !empty($manualCreate);
$start = (int)($row['work_hour_start'] ?? 9);
$end = (int)($row['work_hour_end'] ?? 22);
?>
<?php if ($showHours): ?>
<fieldset class="form-fieldset">
  <legend><?= te('calendar.settings.work_hours') ?></legend>
  <p class="muted"><?= te('calendar.settings.work_hours_hint') ?></p>
  <div class="form-row-inline">
    <label><?= te('calendar.settings.field_hour_start') ?>
      <input type="number" min="0" max="23" name="work_hour_start" value="<?= $start ?>">
    </label>
    <label><?= te('calendar.settings.field_hour_end') ?>
      <input type="number" min="0" max="23" name="work_hour_end" value="<?= $end ?>">
    </label>
  </div>
</fieldset>
<?php endif; ?>

<fieldset class="form-fieldset">
  <legend><?= te('calendar.settings.manual_create') ?></legend>
  <p class="muted"><?= te('calendar.settings.manual_create_hint') ?></p>
  <label class="logo-remove">
    <input type="hidden" name="calendar_manual_create" value="0">
    <input type="checkbox" name="calendar_manual_create" value="1"<?= $manualCreate ? ' checked' : '' ?>>
    <?= te('calendar.settings.manual_create_label') ?>
  </label>
</fieldset>
