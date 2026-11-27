<?php
declare(strict_types=1);
/**
 * @var string $rules_text
 * @var array<string,mixed> $rules_settings camera or post SETTINGS_JSON decoded
 */
$rules_text = (string)($rules_text ?? '');
$rulesSettings = is_array($rules_settings ?? null) ? $rules_settings : [];
$monitor = is_array($rulesSettings['monitor'] ?? null) ? $rulesSettings['monitor'] : [];
$dutyIds = array_map('intval', is_array($rulesSettings['duty_status_ids'] ?? null) ? $rulesSettings['duty_status_ids'] : []);
$dutyStatuses = class_exists('StatusesRepository', false) ? StatusesRepository::listAll() : [];
?>
<fieldset class="video-rules">
  <legend><?= te('video.rules.title') ?></legend>
  <p class="field-help"><?= te('video.rules.help') ?></p>
  <textarea name="rules_text" rows="4" class="video-rules-text" spellcheck="false" placeholder="<?= te('video.rules.placeholder') ?>"><?= e($rules_text) ?></textarea>

  <p class="field-help"><?= te('video.rules.monitor_help') ?></p>
  <div class="form-row">
    <label><?= te('video.rules.monitor_samples') ?>
      <input type="number" name="monitor_samples" min="0" max="<?= (int)VideoRules::MAX_SAMPLES ?>" value="<?= (int)($monitor['samples'] ?? 0) ?>">
    </label>
    <label><?= te('video.rules.monitor_interval') ?>
      <input type="number" name="monitor_interval_sec" min="0" max="60" value="<?= (int)($monitor['sample_interval_sec'] ?? 0) ?>">
    </label>
  </div>

  <?php if ($dutyStatuses !== []) : ?>
    <p class="field-help"><?= te('video.rules.duty_help') ?></p>
    <input type="hidden" name="duty_form" value="1">
    <div class="video-duty-statuses">
      <?php foreach ($dutyStatuses as $st) : ?>
        <?php $sid = (int)$st['ID']; ?>
        <label class="check">
          <input type="checkbox" name="duty_status_ids[]" value="<?= $sid ?>"<?= in_array($sid, $dutyIds, true) ? ' checked' : '' ?>>
          <?= e((string)$st['NAME']) ?>
        </label>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</fieldset>
