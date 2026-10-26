<?php declare(strict_types=1);
$r = $row ?? [];
$s = $settings ?? [];
?>
<section>
  <?php if (!empty($error)): ?><p class="error"><?= e((string)$error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <label><?= te('video.col.name') ?>
      <input name="name" required value="<?= e((string)($r['NAME'] ?? '')) ?>">
    </label>
    <label><?= te('video.col.post') ?>
      <select name="post_id">
        <option value="0">—</option>
        <?php foreach ($posts as $p): ?>
          <option value="<?= (int)$p['ID'] ?>" <?= ((int)($r['POST_ID'] ?? 0) === (int)$p['ID']) ? 'selected' : '' ?>>
            <?= e((string)$p['NAME']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>RTSP URL
      <input name="rtsp_url" class="wide" value="<?= e((string)($r['RTSP_URL'] ?? '')) ?>" placeholder="rtsp://…">
    </label>
    <label><?= te('video.col.mode') ?>
      <select name="mode">
        <?php foreach (['monitoring', 'dataset'] as $m): ?>
          <option value="<?= $m ?>" <?= (($r['MODE'] ?? '') === $m) ? 'selected' : '' ?>><?= e($m) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('video.col.model') ?>
      <select name="model_id">
        <option value="0">—</option>
        <?php foreach ($models as $m): ?>
          <option value="<?= (int)$m['ID'] ?>" <?= ((int)($r['MODEL_ID'] ?? 0) === (int)$m['ID']) ? 'selected' : '' ?>>
            <?= e((string)$m['NAME']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><input type="checkbox" name="enabled" value="1" <?= !empty($r['ENABLED']) || ($isNew ?? false) ? 'checked' : '' ?>> <?= te('video.col.enabled') ?></label>
    <label><?= te('video.col.conf') ?>
      <input type="number" step="0.01" name="conf_threshold" value="<?= e((string)($r['CONF_THRESHOLD'] ?? '0.25')) ?>">
    </label>
    <label><?= te('video.col.iou') ?>
      <input type="number" step="0.01" name="iou_threshold" value="<?= e((string)($r['IOU_THRESHOLD'] ?? '0.45')) ?>">
    </label>
    <label><?= te('video.col.interval') ?>
      <input type="number" name="interval_sec" value="<?= e((string)($r['INTERVAL_SEC'] ?? '30')) ?>">
    </label>
    <label><?= te('video.col.crop') ?>
      <input type="number" step="0.1" name="crop_height_pct" value="<?= e((string)($s['crop_height_pct'] ?? '0')) ?>">
    </label>
    <label><?= te('video.col.rotate') ?>
      <input type="number" name="rotate_deg" value="<?= e((string)($s['rotate_deg'] ?? '0')) ?>">
    </label>
    <label><?= te('video.col.dataset_project') ?>
      <input type="number" name="dataset_project_id" value="<?= e((string)($s['dataset_project_id'] ?? '0')) ?>">
    </label>
    <?php
    $sched = $sched ?? ['mode' => 'always'];
    require __DIR__ . '/_schedule_fields.php';
    ?>
    <p>
      <button type="submit"><?= te('core.common.save') ?></button>
      <?php if (empty($isNew) && Auth::can('video.delete')): ?>
        <button type="submit" name="action" value="delete" onclick="return confirm(<?= tj('core.bulk.confirm_delete') ?>)"><?= te('core.common.delete') ?></button>
      <?php endif; ?>
    </p>
  </form>
</section>
