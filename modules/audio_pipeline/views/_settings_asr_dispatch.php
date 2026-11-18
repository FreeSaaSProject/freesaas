<?php
declare(strict_types=1);
/**
 * Thin pointer: hosts + audio pull/push live on /edge-hosts (single SoT).
 *
 * @var bool $tablesReady
 * @var bool $pullEnabled
 * @var bool $pushEnabled
 * @var array{active_pull:int,active_push:int} $counts
 * @var int $pending
 */
?>
<input type="hidden" name="asr_dispatch_save" value="1">

<fieldset class="form-fieldset">
  <legend><?= te('audio_pipeline.asr.tab') ?></legend>
  <p><?= te('audio_pipeline.asr.redirect_lede') ?></p>
  <p><a class="btn" href="/edge-hosts"><?= te('audio_pipeline.asr.edge_hosts_link') ?></a></p>
  <?php if (!empty($tablesReady)): ?>
    <p class="muted small"><?= te('audio_pipeline.asr.queue_state', [
        'pending' => (string)(int)$pending,
        'pull' => (string)(int)($counts['active_pull'] ?? 0),
        'push' => (string)(int)($counts['active_push'] ?? 0),
    ]) ?></p>
    <p class="muted small"><?= te('audio_pipeline.asr.redirect_mode', [
        'pull' => !empty($pullEnabled) ? te('core.common.on') : te('core.common.off'),
        'push' => !empty($pushEnabled) ? te('core.common.on') : te('core.common.off'),
    ]) ?></p>
  <?php else: ?>
    <p class="muted small"><?= te('audio_pipeline.asr.tables_missing') ?></p>
  <?php endif; ?>
</fieldset>
