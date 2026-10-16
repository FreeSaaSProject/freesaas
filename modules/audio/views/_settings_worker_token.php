<?php
declare(strict_types=1);
/** @var bool $workerTokenConfigured */
/** @var bool $workerTokenJustGenerated */
/** @var string $workerTokenPlain */
/** @var string $workerTokenMasked */
$workerTokenConfigured = !empty($workerTokenConfigured);
$workerTokenJustGenerated = !empty($workerTokenJustGenerated);
$workerTokenPlain = (string)($workerTokenPlain ?? '');
$workerTokenMasked = (string)($workerTokenMasked ?? '');
?>
<fieldset class="settings-worker-token">
  <legend><?= te('audio.settings.worker_token.title') ?></legend>
  <p class="muted"><?= te('audio.settings.worker_token.help') ?></p>
  <p class="muted"><code>X-Audio-Token</code> →
    <code>/audio/asr/claim</code>,
    <code>/audio/asr/result</code>,
    <code>/asr/voice-transcribe/worker</code>,
    <code>/audio/jobs/*</code>
  </p>

  <?php if ($workerTokenJustGenerated && $workerTokenPlain !== ''): ?>
    <div class="flash flash-ok">
      <?= te('audio.settings.worker_token.generated_once') ?>
      <p style="margin:.5rem 0 0;word-break:break-all">
        <code id="audio-worker-token-value"><?= e($workerTokenPlain) ?></code>
      </p>
      <p class="actions" style="margin-top:.5rem">
        <button type="button" class="btn-secondary" data-copy-text="<?= e($workerTokenPlain) ?>"><?= te('audio.settings.worker_token.btn_copy') ?></button>
      </p>
    </div>
  <?php elseif ($workerTokenConfigured): ?>
    <p>
      <?= te('audio.settings.worker_token.status_ok') ?>
      <code><?= e($workerTokenMasked) ?></code>
    </p>
  <?php else: ?>
    <p class="muted"><?= te('audio.settings.worker_token.status_missing') ?></p>
  <?php endif; ?>

  <p class="muted" style="margin-top:.5rem"><?= te('audio.settings.worker_token.save_note') ?></p>
</fieldset>
