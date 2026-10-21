<?php
declare(strict_types=1);
/**
 * Calendar module settings form sections.
 *
 * @var ?array $row
 * @var bool $showHours
 * @var string $eventMode blocks|visits
 * @var int $compactChipMinutes
 */
$row = is_array($row ?? null) ? $row : [];
$showHours = !empty($showHours);
$eventMode = (string)($eventMode ?? CalendarConfig::MODE_BLOCKS);
if ($eventMode !== CalendarConfig::MODE_VISITS) {
    $eventMode = CalendarConfig::MODE_BLOCKS;
}
$compactChipMinutes = (int)($compactChipMinutes ?? CalendarConfig::compactChipMinutes());
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
  <legend><?= te('calendar.settings.event_mode') ?></legend>
  <p class="muted"><?= te('calendar.settings.event_mode_hint') ?></p>
  <label class="logo-remove">
    <input type="radio" name="calendar_event_mode" value="blocks"<?= $eventMode === 'blocks' ? ' checked' : '' ?>>
    <?= te('calendar.settings.event_mode_blocks') ?>
  </label>
  <label class="logo-remove">
    <input type="radio" name="calendar_event_mode" value="visits"<?= $eventMode === 'visits' ? ' checked' : '' ?>>
    <?= te('calendar.settings.event_mode_visits') ?>
  </label>
</fieldset>

<fieldset class="form-fieldset">
  <legend><?= te('calendar.settings.compact_chips') ?></legend>
  <p class="muted"><?= te('calendar.settings.compact_chips_hint') ?></p>
  <label><?= te('calendar.settings.field_compact_chip_minutes') ?>
    <input type="number" min="0" max="<?= (int)CalendarConfig::MAX_COMPACT_CHIP_MINUTES ?>"
           name="calendar_compact_chip_minutes" value="<?= $compactChipMinutes ?>">
  </label>
</fieldset>
