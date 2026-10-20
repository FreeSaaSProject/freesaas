<?php declare(strict_types=1); ?>
<section>
  <?php if (!empty($message)): ?><p class="ok"><?= e((string)$message) ?></p><?php endif; ?>
  <?php if (!empty($error)): ?><p class="error"><?= e((string)$error) ?></p><?php endif; ?>
  <?php if (!empty($newToken)): ?>
    <p class="ok"><?= te('video.settings.token_once') ?>: <code><?= e((string)$newToken) ?></code></p>
  <?php endif; ?>

  <form method="post" class="form">
    <fieldset>
      <legend><?= te('video.settings.worker') ?></legend>
      <p class="muted"><?= te('video.settings.token_current', ['masked' => (string)($cfg['worker_token_masked'] ?? '')]) ?></p>
      <label><?= te('video.settings.worker_token') ?>
        <input type="password" name="worker_token" value="" autocomplete="new-password" placeholder="<?= te('video.settings.token_keep') ?>">
      </label>
      <label><input type="checkbox" name="regenerate_token" value="1"> <?= te('video.settings.regenerate') ?></label>
    </fieldset>
    <fieldset>
      <legend><?= te('video.settings.retention') ?></legend>
      <label><?= te('video.settings.retention_days') ?>
        <input type="number" name="retention_days" value="<?= (int)($cfg['retention_days'] ?? 7) ?>">
      </label>
      <label><?= te('video.settings.max_storage_mb') ?>
        <input type="number" name="max_storage_mb" value="<?= (int)($cfg['max_storage_mb'] ?? 2048) ?>">
      </label>
      <label><input type="checkbox" name="keep_alert_media" value="1" <?= !empty($cfg['keep_alert_media']) ? 'checked' : '' ?>> <?= te('video.settings.keep_alert_media') ?></label>
      <label><?= te('video.settings.latest_frame_ring') ?>
        <input type="number" name="latest_frame_ring" value="<?= (int)($cfg['latest_frame_ring'] ?? 3) ?>">
      </label>
      <label><?= te('video.settings.purge_mode') ?>
        <select name="purge_mode">
          <option value="default" <?= (($cfg['purge_mode'] ?? '') === 'default') ? 'selected' : '' ?>><?= te('video.settings.purge_default') ?></option>
          <option value="alerts_only" <?= (($cfg['purge_mode'] ?? '') === 'alerts_only') ? 'selected' : '' ?>><?= te('video.settings.purge_alerts_only') ?></option>
        </select>
      </label>
      <p class="muted"><?= te('video.settings.storage_now', ['mb' => number_format((int)($cfg['storage_bytes'] ?? 0) / 1048576, 1)]) ?></p>
    </fieldset>
    <button type="submit"><?= te('core.common.save') ?></button>
  </form>
  <form method="post" style="margin-top:1rem">
    <input type="hidden" name="run_purge" value="1">
    <button type="submit"><?= te('video.settings.run_purge') ?></button>
  </form>
</section>
