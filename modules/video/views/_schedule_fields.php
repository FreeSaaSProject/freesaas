<?php
declare(strict_types=1);
/** @var array<string,mixed> $sched */
$sched = $sched ?? [];
$mode = (string)($sched['mode'] ?? 'always');
?>
<fieldset class="video-schedule">
  <legend><?= te('video.schedule.title') ?></legend>
  <p class="field-help"><?= te('video.schedule.help') ?></p>
  <label><?= te('video.schedule.mode') ?>
    <select name="schedule_mode">
      <option value="always" <?= $mode === 'always' ? 'selected' : '' ?>><?= te('video.schedule.mode_always') ?></option>
      <option value="simple" <?= $mode === 'simple' ? 'selected' : '' ?>><?= te('video.schedule.mode_simple') ?></option>
      <option value="weekly" <?= $mode === 'weekly' ? 'selected' : '' ?>><?= te('video.schedule.mode_weekly') ?></option>
    </select>
  </label>
  <div class="video-schedule-simple">
    <label><?= te('video.schedule.start') ?>
      <input type="number" name="schedule_start_hour" min="0" max="23" value="<?= (int)($sched['start_hour'] ?? 10) ?>">
      :
      <input type="number" name="schedule_start_minute" min="0" max="59" value="<?= (int)($sched['start_minute'] ?? 0) ?>">
    </label>
    <label><?= te('video.schedule.end') ?>
      <input type="number" name="schedule_end_hour" min="0" max="23" value="<?= (int)($sched['end_hour'] ?? 21) ?>">
      :
      <input type="number" name="schedule_end_minute" min="0" max="59" value="<?= (int)($sched['end_minute'] ?? 45) ?>">
    </label>
  </div>
  <p class="field-help"><?= te('video.schedule.weekly_hint') ?></p>
  <table class="table video-schedule-weekly">
    <thead><tr>
      <th><?= te('video.schedule.day') ?></th>
      <th><?= te('video.schedule.closed') ?></th>
      <th><?= te('video.schedule.start') ?></th>
      <th><?= te('video.schedule.end') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach (VideoSchedule::DOW as $d):
      $w = $sched['weekly'][$d] ?? [];
      ?>
      <tr>
        <td><?= e($d) ?></td>
        <td><input type="checkbox" name="schedule_<?= e($d) ?>_closed" value="1" <?= !empty($w['closed']) ? 'checked' : '' ?>></td>
        <td>
          <input type="number" name="schedule_<?= e($d) ?>_start_h" min="0" max="23" value="<?= (int)($w['start_hour'] ?? 10) ?>">
          <input type="number" name="schedule_<?= e($d) ?>_start_m" min="0" max="59" value="<?= (int)($w['start_minute'] ?? 0) ?>">
        </td>
        <td>
          <input type="number" name="schedule_<?= e($d) ?>_end_h" min="0" max="23" value="<?= (int)($w['end_hour'] ?? 21) ?>">
          <input type="number" name="schedule_<?= e($d) ?>_end_m" min="0" max="59" value="<?= (int)($w['end_minute'] ?? 45) ?>">
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <label><?= te('video.schedule.overrides') ?>
    <textarea name="schedule_overrides" rows="3" placeholder='{"2026-12-31":{"closed":true}}'><?= e(json_encode($sched['monthly_overrides'] ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></textarea>
  </label>
  <p class="field-help"><?= te('video.schedule.overrides.help') ?></p>
  <label><?= te('video.schedule.dataset_shift_count') ?>
    <input type="number" name="dataset_shift_count" min="0" value="<?= (int)($sched['dataset_shift_count'] ?? 0) ?>">
  </label>
  <label><?= te('video.schedule.dataset_interval_sec') ?>
    <input type="number" name="dataset_interval_sec" min="0" value="<?= (int)($sched['dataset_interval_sec'] ?? 0) ?>">
  </label>
</fieldset>
