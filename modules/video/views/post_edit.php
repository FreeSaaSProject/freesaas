<?php declare(strict_types=1); ?>
<section>
  <?php if (!empty($error)): ?><p class="error"><?= e((string)$error) ?></p><?php endif; ?>
  <form method="post" class="form video-form">
    <input type="hidden" name="action" value="save">
    <div class="video-form-row">
      <label class="video-field"><?= te('video.col.name') ?>
        <input name="name" required value="<?= e((string)($row['NAME'] ?? '')) ?>">
      </label>
      <label class="video-field"><?= te('video.col.model') ?>
        <select name="model_id">
          <option value="0">—</option>
          <?php foreach ($models as $m): ?>
            <option value="<?= (int)$m['ID'] ?>" <?= ((int)($row['MODEL_ID'] ?? 0) === (int)$m['ID']) ? 'selected' : '' ?>>
              <?= e((string)$m['NAME']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <p class="field-help"><?= te('video.posts.model.help') ?></p>
    <label><input type="checkbox" name="enabled" value="1" <?= !empty($row['ENABLED']) ? 'checked' : '' ?>> <?= te('video.col.enabled') ?></label>
    <?php
    $sched = $sched ?? ['mode' => 'always'];
    require __DIR__ . '/_schedule_fields.php';
    ?>
    <div class="video-form-actions">
      <button type="submit" class="btn btn-primary"><?= te('core.common.save') ?></button>
      <?php if (empty($isNew)): ?>
        <a class="btn btn-ghost" href="/video/posts/<?= (int)($row['ID'] ?? 0) ?>"><?= te('video.posts.open') ?></a>
      <?php endif; ?>
    </div>
  </form>
  <?php if (empty($isNew)): ?>
    <form method="post" class="video-purge-form" onsubmit="return confirm(<?= tj('core.bulk.confirm_delete') ?>)">
      <input type="hidden" name="action" value="delete">
      <button type="submit" class="btn btn-danger btn-sm"><?= te('core.common.delete') ?></button>
    </form>
  <?php endif; ?>
</section>
