<?php declare(strict_types=1); ?>
<section class="video-settings">
  <?php if (!empty($message)): ?><p class="ok"><?= e((string)$message) ?></p><?php endif; ?>
  <?php if (!empty($error)): ?><p class="error"><?= e((string)$error) ?></p><?php endif; ?>
  <?php if (!empty($newToken)): ?>
    <p class="ok"><?= te('video.settings.token_once') ?>: <code><?= e((string)$newToken) ?></code></p>
    <p class="muted"><?= te('video.settings.token_mac_hint') ?></p>
  <?php endif; ?>

  <form method="post" class="form">
    <fieldset>
      <legend><?= te('video.settings.worker') ?></legend>
      <p class="muted"><?= te('video.settings.worker.help') ?></p>
      <p class="muted"><?= te('video.settings.token_current', ['masked' => (string)($cfg['worker_token_masked'] ?? '')]) ?></p>
      <label><?= te('video.settings.worker_token') ?>
        <input type="password" name="worker_token" value="" autocomplete="new-password" placeholder="<?= te('video.settings.token_keep') ?>">
      </label>
      <p class="field-help"><?= te('video.settings.worker_token.help') ?></p>
      <label><input type="checkbox" name="regenerate_token" value="1"> <?= te('video.settings.regenerate') ?></label>
      <p class="field-help"><?= te('video.settings.regenerate.help') ?></p>
    </fieldset>
    <fieldset>
      <legend><?= te('video.settings.dispatch') ?></legend>
      <p class="muted"><?= te('video.settings.dispatch.help') ?></p>
      <input type="hidden" name="dispatch_save" value="1">
      <label><input type="checkbox" name="dispatch_pull_enabled" value="1" <?= !empty($cfg['dispatch_pull_enabled']) ? 'checked' : '' ?>> <?= te('video.settings.dispatch_pull') ?></label>
      <p class="field-help"><?= te('video.settings.dispatch_pull.help') ?></p>
      <label><input type="checkbox" name="dispatch_push_enabled" value="1" <?= !empty($cfg['dispatch_push_enabled']) ? 'checked' : '' ?>> <?= te('video.settings.dispatch_push') ?></label>
      <p class="field-help"><?= te('video.settings.dispatch_push.help') ?></p>
      <?php $edgeHosts = is_array($cfg['edge_hosts'] ?? null) ? $cfg['edge_hosts'] : []; ?>
      <?php if ($edgeHosts === []): ?>
        <p class="muted"><?= te('video.settings.edge_none') ?></p>
      <?php else: ?>
        <ul class="muted">
          <?php foreach ($edgeHosts as $eh): ?>
            <li><?= e((string)$eh['name']) ?>:
              <?= te('video.settings.edge_state', [
                  'at' => VideoTime::stamp((int)($eh['last']['at'] ?? 0)),
                  'held' => (int)($eh['last']['held'] ?? 0),
              ]) ?>
              <?php if ($eh['error'] !== ''): ?> — <strong><?= e($eh['error']) ?></strong><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <p class="field-help"><a href="/edge-hosts"><?= te('video.settings.edge_link') ?></a></p>
    </fieldset>
    <fieldset>
      <legend><?= te('video.settings.retention') ?></legend>
      <p class="muted"><?= te('video.settings.retention.help') ?></p>
      <label><?= te('video.settings.retention_days') ?>
        <input type="number" name="retention_days" value="<?= (int)($cfg['retention_days'] ?? 7) ?>">
      </label>
      <p class="field-help"><?= te('video.settings.retention_days.help') ?></p>
      <label><?= te('video.settings.max_storage_mb') ?>
        <input type="number" name="max_storage_mb" value="<?= (int)($cfg['max_storage_mb'] ?? 2048) ?>">
      </label>
      <p class="field-help"><?= te('video.settings.max_storage_mb.help') ?></p>
      <label><input type="checkbox" name="keep_alert_media" value="1" <?= !empty($cfg['keep_alert_media']) ? 'checked' : '' ?>> <?= te('video.settings.keep_alert_media') ?></label>
      <p class="field-help"><?= te('video.settings.keep_alert_media.help') ?></p>
      <label><?= te('video.settings.latest_frame_ring') ?>
        <input type="number" name="latest_frame_ring" value="<?= (int)($cfg['latest_frame_ring'] ?? 3) ?>">
      </label>
      <p class="field-help"><?= te('video.settings.latest_frame_ring.help') ?></p>
      <label><?= te('video.settings.purge_mode') ?>
        <select name="purge_mode">
          <option value="default" <?= (($cfg['purge_mode'] ?? '') === 'default') ? 'selected' : '' ?>><?= te('video.settings.purge_default') ?></option>
          <option value="alerts_only" <?= (($cfg['purge_mode'] ?? '') === 'alerts_only') ? 'selected' : '' ?>><?= te('video.settings.purge_alerts_only') ?></option>
        </select>
      </label>
      <p class="field-help"><?= te('video.settings.purge_mode.help') ?></p>
      <p class="muted"><?= te('video.settings.storage_now', ['mb' => number_format((int)($cfg['storage_bytes'] ?? 0) / 1048576, 1)]) ?></p>
    </fieldset>
    <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
  </form>
  <form method="post" class="video-purge-form">
    <input type="hidden" name="run_purge" value="1">
    <p class="field-help"><?= te('video.settings.run_purge.help') ?></p>
    <button type="submit" class="btn-secondary"><?= te('video.settings.run_purge') ?></button>
  </form>
</section>
