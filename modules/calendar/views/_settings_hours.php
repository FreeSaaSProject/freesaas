<?php
/**
 * Settings section contributed to /settings when calendar is enabled.
 *
 * @var ?array $row
 */
$row = is_array($row ?? null) ? $row : [];
$start = (int)($row['work_hour_start'] ?? 9);
$end = (int)($row['work_hour_end'] ?? 22);
?>
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
