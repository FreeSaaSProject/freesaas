<?php declare(strict_types=1);
$r = $row ?? [];
$s = $settings ?? [];
?>
<section>
  <?php if (!empty($error)): ?><p class="error"><?= e((string)$error) ?></p><?php endif; ?>
  <form method="post" class="form video-form">
    <div class="video-form-row">
      <label class="video-field"><?= te('video.col.name') ?>
        <input name="name" required value="<?= e((string)($r['NAME'] ?? '')) ?>">
      </label>
      <label class="video-field"><?= te('video.col.post') ?>
        <select name="post_id">
          <option value="0">—</option>
          <?php foreach ($posts as $p): ?>
            <option value="<?= (int)$p['ID'] ?>" <?= ((int)($r['POST_ID'] ?? 0) === (int)$p['ID']) ? 'selected' : '' ?>>
              <?= e((string)$p['NAME']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label class="video-field"><?= te('video.col.rtsp') ?>
      <input name="rtsp_url" class="wide" value="<?= e((string)($r['RTSP_URL'] ?? '')) ?>" placeholder="rtsp://…">
    </label>
    <div class="video-form-row">
      <label class="video-field"><?= te('video.col.mode') ?>
        <select name="mode">
          <?php foreach (['monitoring', 'dataset'] as $m): ?>
            <option value="<?= $m ?>" <?= (($r['MODE'] ?? '') === $m) ? 'selected' : '' ?>><?= e($m) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="video-field"><?= te('video.col.model') ?>
        <select name="model_id">
          <option value="0">—</option>
          <?php foreach ($models as $m): ?>
            <option value="<?= (int)$m['ID'] ?>" <?= ((int)($r['MODEL_ID'] ?? 0) === (int)$m['ID']) ? 'selected' : '' ?>>
              <?= e((string)$m['NAME']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="video-field" style="flex:0 0 auto;justify-content:flex-end">
        <span>&nbsp;</span>
        <span><input type="checkbox" name="enabled" value="1" <?= !empty($r['ENABLED']) || ($isNew ?? false) ? 'checked' : '' ?>> <?= te('video.col.enabled') ?></span>
      </label>
    </div>
    <div class="video-form-row">
      <label class="video-field"><?= te('video.col.conf') ?>
        <input type="number" step="0.01" name="conf_threshold" value="<?= e((string)($r['CONF_THRESHOLD'] ?? '0.25')) ?>">
      </label>
      <label class="video-field"><?= te('video.col.iou') ?>
        <input type="number" step="0.01" name="iou_threshold" value="<?= e((string)($r['IOU_THRESHOLD'] ?? '0.45')) ?>">
      </label>
      <label class="video-field"><?= te('video.col.interval') ?>
        <input type="number" name="interval_sec" value="<?= e((string)($r['INTERVAL_SEC'] ?? '30')) ?>">
      </label>
    </div>
    <details class="video-schedule-advanced">
      <summary><?= te('video.cameras.advanced') ?></summary>
      <div class="video-form-row">
        <label class="video-field"><?= te('video.col.crop') ?>
          <input type="number" step="0.1" name="crop_height_pct" value="<?= e((string)($s['crop_height_pct'] ?? '0')) ?>">
        </label>
        <label class="video-field"><?= te('video.col.rotate') ?>
          <input type="number" name="rotate_deg" value="<?= e((string)($s['rotate_deg'] ?? '0')) ?>">
        </label>
        <label class="video-field"><?= te('video.col.dataset_project') ?>
          <input type="number" name="dataset_project_id" value="<?= e((string)($s['dataset_project_id'] ?? '0')) ?>">
        </label>
      </div>
    </details>
    <?php
    $sched = $sched ?? ['mode' => 'always'];
    require __DIR__ . '/_schedule_fields.php';
    ?>
    <div class="video-form-actions">
      <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
      <?php if (empty($isNew)): ?>
        <a class="btn-ghost" href="/video/cameras/<?= (int)($r['ID'] ?? 0) ?>/live"><?= te('video.live.crumb') ?></a>
      <?php endif; ?>
      <?php if (empty($isNew) && Auth::can('video.delete')): ?>
        <button type="submit" name="action" value="delete" class="btn-danger btn-sm" onclick="return confirm(<?= tj('core.bulk.confirm_delete') ?>)"><?= te('core.common.delete') ?></button>
      <?php endif; ?>
    </div>
  </form>
</section>
