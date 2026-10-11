<?php
declare(strict_types=1);
/** @var array<string,int> $bounds */
$fields = [
    'rate_start' => ['hourStartOffRate', 'minStartOffRate'],
    'rate_end' => ['hourEndOffRate', 'minEndOffRate'],
    'record_start' => ['hourStartOffRecord', 'minStartOffRecord'],
    'record_end' => ['hourEndOffRecord', 'minEndOffRecord'],
];
?>
<fieldset class="form-fieldset">
  <legend><?= te('audio_reports.settings.day_bounds') ?></legend>
  <p class="muted"><?= te('audio_reports.settings.day_bounds_hint') ?></p>
  <input type="hidden" name="audio_day_bounds_save" value="1">
  <?php foreach ($fields as $labelKey => [$h, $m]): ?>
  <div class="form-row-inline">
    <span><?= te('audio_reports.settings.' . $labelKey) ?></span>
    <label><?= te('audio_reports.settings.hour') ?>
      <input type="number" min="0" max="23" name="<?= e($h) ?>" value="<?= (int)($bounds[$h] ?? 0) ?>">
    </label>
    <label><?= te('audio_reports.settings.min') ?>
      <input type="number" min="0" max="59" name="<?= e($m) ?>" value="<?= (int)($bounds[$m] ?? 0) ?>">
    </label>
  </div>
  <?php endforeach; ?>
</fieldset>
