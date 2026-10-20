<?php declare(strict_types=1);
$r = $row ?? [];
?>
<section>
  <?php if (!empty($error)): ?><p class="error"><?= e((string)$error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <label><?= te('video_label.col.name') ?>
      <input name="name" required value="<?= e((string)($r['NAME'] ?? '')) ?>">
    </label>
    <label><?= te('video_label.col.classes') ?>
      <textarea name="classes" rows="5" placeholder="admin&#10;guest&#10;staff"><?= e((string)$classText) ?></textarea>
    </label>
    <label><?= te('video_label.col.model') ?>
      <select name="model_id">
        <option value="0">—</option>
        <?php foreach ($models as $m): ?>
          <option value="<?= (int)$m['ID'] ?>" <?= ((int)($r['MODEL_ID'] ?? 0) === (int)$m['ID']) ? 'selected' : '' ?>>
            <?= e((string)$m['NAME']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('video_label.col.conf') ?>
      <input type="number" step="0.01" name="conf_threshold" value="<?= e((string)($r['CONF_THRESHOLD'] ?? '0.25')) ?>">
    </label>
    <label><?= te('video_label.col.iou') ?>
      <input type="number" step="0.01" name="iou_threshold" value="<?= e((string)($r['IOU_THRESHOLD'] ?? '0.45')) ?>">
    </label>
    <p>
      <button type="submit"><?= te('core.common.save') ?></button>
      <?php if (empty($isNew)): ?>
        <button type="submit" name="action" value="delete" onclick="return confirm(<?= tj('core.bulk.confirm_delete') ?>)"><?= te('core.common.delete') ?></button>
      <?php endif; ?>
    </p>
  </form>
</section>
