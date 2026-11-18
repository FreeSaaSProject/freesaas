<?php
declare(strict_types=1);
/**
 * Audio dispatch policy on /edge-hosts (ASR + diarize share these switches).
 *
 * @var bool $pullEnabled
 * @var bool $pushEnabled
 * @var int $pushEveryMin
 * @var array{active_pull:int,active_push:int} $counts
 * @var int $pending
 * @var bool $tablesReady
 */
?>
<input type="hidden" name="edge_audio_dispatch_save" value="1">
<fieldset class="form-fieldset">
  <legend><?= te('audio_pipeline.asr.channels') ?></legend>
  <p class="muted"><?= te('audio_pipeline.asr.channels_hint_edge') ?></p>
  <label class="checkbox">
    <input type="checkbox" name="asr_pull_enabled" value="1"<?= !empty($pullEnabled) ? ' checked' : '' ?>>
    <?= te('audio_pipeline.asr.pull') ?>
  </label>
  <p class="muted small"><?= te('audio_pipeline.asr.pull_hint') ?></p>
  <label class="checkbox">
    <input type="checkbox" name="asr_push_enabled" value="1"<?= !empty($pushEnabled) ? ' checked' : '' ?><?= !empty($tablesReady) ? '' : ' disabled' ?>>
    <?= te('audio_pipeline.asr.push') ?>
  </label>
  <p class="muted small"><?= te('audio_pipeline.asr.push_hint_edge') ?></p>
  <div class="form-row-inline">
    <label><?= te('audio_pipeline.asr.every_min') ?>
      <input type="number" min="1" max="60" name="asr_push_every_min" value="<?= (int)($pushEveryMin ?? 2) ?>" style="width:5em">
    </label>
  </div>
  <p class="muted small"><?= te('audio_pipeline.asr.every_min_hint') ?></p>
  <?php if (!empty($tablesReady)): ?>
    <p class="muted small"><?= te('audio_pipeline.asr.queue_state', [
        'pending' => (string)(int)$pending,
        'pull' => (string)(int)($counts['active_pull'] ?? 0),
        'push' => (string)(int)($counts['active_push'] ?? 0),
    ]) ?></p>
  <?php endif; ?>
</fieldset>
