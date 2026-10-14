<?php
declare(strict_types=1);
/**
 * @var bool $withReparse
 * @var bool $cronEnabled
 * @var int $words
 * @var int $minInstances
 * @var int $deltaSurround
 * @var string $dailyAt
 * @var int $backfillDays
 */
$withReparse = !empty($withReparse);
$cronEnabled = !empty($cronEnabled);
$words = max(2, min(8, (int)($words ?? 3)));
$minInstances = max(1, (int)($minInstances ?? 3));
$deltaSurround = max(0, min(10, (int)($deltaSurround ?? 3)));
$dailyAt = (string)($dailyAt ?? '03:40');
$backfillDays = max(0, min(14, (int)($backfillDays ?? 3)));
?>
<fieldset class="form-fieldset">
  <legend><?= te('audio_phrases.settings.legend') ?></legend>
  <p class="muted"><?= te('audio_phrases.settings.hint') ?></p>
  <input type="hidden" name="audio_phrases_settings_save" value="1">
  <label class="checkbox">
    <input type="checkbox" name="audio_phrases_with_reparse" value="1"<?= $withReparse ? ' checked' : '' ?>>
    <?= te('audio_phrases.settings.with_reparse') ?>
  </label>
  <p class="muted small"><?= te('audio_phrases.settings.with_reparse_hint') ?></p>
  <label class="checkbox">
    <input type="checkbox" name="audio_phrases_cron_enabled" value="1"<?= $cronEnabled ? ' checked' : '' ?>>
    <?= te('audio_phrases.settings.cron_enabled') ?>
  </label>
  <p class="muted small"><?= te('audio_phrases.settings.cron_enabled_hint') ?></p>
  <p class="muted small"><?= te('audio_phrases.settings.stop_hint') ?></p>
  <div class="form-row-inline">
    <label><?= te('audio_phrases.settings.words') ?>
      <input type="number" min="2" max="8" name="audio_phrases_words" value="<?= $words ?>">
    </label>
    <label><?= te('audio_phrases.settings.min_instances') ?>
      <input type="number" min="1" name="audio_phrases_min_instances" value="<?= $minInstances ?>">
    </label>
    <label><?= te('audio_phrases.settings.delta_surround') ?>
      <input type="number" min="0" max="10" name="audio_phrases_delta_surround" value="<?= $deltaSurround ?>">
    </label>
  </div>
  <div class="form-row-inline">
    <label><?= te('audio_phrases.settings.daily_at') ?>
      <input type="time" name="audio_phrases_daily_at" value="<?= e($dailyAt) ?>">
    </label>
    <label><?= te('audio_phrases.settings.backfill_days') ?>
      <input type="number" min="0" max="14" name="audio_phrases_backfill_days" value="<?= $backfillDays ?>">
    </label>
  </div>
</fieldset>
