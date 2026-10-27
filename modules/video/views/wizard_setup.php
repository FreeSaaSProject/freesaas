<?php declare(strict_types=1); ?>
<section class="video-wizard">
  <?php
  $setupSteps = [
    1 => 'video.wizard.setup.step1',
    2 => 'video.wizard.setup.step2',
    3 => 'video.wizard.setup.step3',
    4 => 'video.wizard.setup.step4',
    5 => 'video.wizard.setup.step5',
  ];
  ?>
  <ol class="video-wizard-steps">
    <?php foreach ($setupSteps as $i => $key): ?>
      <li class="<?= $i === (int)$step ? 'is-current' : ($i < (int)$step ? 'is-done' : '') ?>">
        <span class="video-step-num"><?= (int)$i ?></span>
        <?= te($key) ?>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php if (!empty($error)): ?><p class="error"><?= e((string)$error) ?></p><?php endif; ?>

  <form method="post" class="form video-form video-wizard-panel">
    <input type="hidden" name="step" value="<?= (int)$step ?>">
    <input type="hidden" name="post_id" value="<?= (int)$postId ?>">
    <input type="hidden" name="camera_id" value="<?= (int)$cameraId ?>">
    <input type="hidden" name="model_id" value="<?= (int)$modelId ?>">

    <?php if ((int)$step === 1): ?>
      <label class="video-field"><?= te('video.col.name') ?>
        <input name="name" required value="<?= e((string)($post['NAME'] ?? '')) ?>">
      </label>
      <label><input type="checkbox" name="enabled" value="1" checked> <?= te('video.col.enabled') ?></label>
      <div class="video-wizard-actions">
        <button type="submit" class="btn-primary"><?= te('video.wizard.next') ?></button>
      </div>

    <?php elseif ((int)$step === 2): ?>
      <div class="video-form-row">
        <label class="video-field"><?= te('video.col.name') ?>
          <input name="name" required value="<?= e((string)($camera['NAME'] ?? '')) ?>">
        </label>
        <label class="video-field"><?= te('video.col.interval') ?>
          <input type="number" name="interval_sec" value="<?= e((string)($camera['INTERVAL_SEC'] ?? '30')) ?>">
        </label>
      </div>
      <label class="video-field"><?= te('video.col.rtsp') ?>
        <input name="rtsp_url" class="wide" required value="<?= e((string)($camera['RTSP_URL'] ?? '')) ?>">
      </label>
      <label><input type="checkbox" name="enabled" value="1" checked> <?= te('video.col.enabled') ?></label>
      <label><input type="checkbox" name="add_another" value="1"> <?= te('video.wizard.setup.add_camera') ?></label>
      <div class="video-wizard-actions">
        <button type="submit" class="btn-primary"><?= te('video.wizard.next') ?></button>
      </div>

    <?php elseif ((int)$step === 3): ?>
      <div class="video-form-row">
        <label class="video-field"><?= te('video.col.name') ?>
          <input name="model_name" value="<?= e((string)($model['NAME'] ?? 'spa-detect')) ?>">
        </label>
        <label class="video-field"><?= te('video.col.model') ?>
          <select name="model_id">
            <option value="0"><?= te('video.wizard.setup.new_model') ?></option>
            <?php foreach ($models as $m): ?>
              <option value="<?= (int)$m['ID'] ?>" <?= (int)$modelId === (int)$m['ID'] ? 'selected' : '' ?>><?= e((string)$m['NAME']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <label class="video-field"><?= te('video.models.weights_path') ?>
        <input name="weights_path" value="<?= e((string)($model['WEIGHTS_PATH'] ?? '')) ?>" placeholder="yolo11n.pt or /path/best.pt">
      </label>
      <label class="video-field"><?= te('video.models.classes') ?>
        <textarea name="classes" rows="5" placeholder="staff|сотрудник #e6194b&#10;guest|гость #3cb44b"><?php
          if ($model) {
              foreach (VideoModelsRepository::classesOf($model) as $cl) {
                  $line = (string)$cl['name'];
                  if (($cl['label'] ?? '') !== '' && $cl['label'] !== $cl['name']) {
                      $line .= '|' . $cl['label'];
                  }
                  if (!empty($cl['color'])) {
                      $line .= ' ' . $cl['color'];
                  }
                  echo e($line) . "\n";
              }
          }
        ?></textarea>
      </label>
      <p class="field-help"><?= te('video.models.classes.help') ?></p>
      <div class="video-wizard-actions">
        <button type="submit" class="btn-primary"><?= te('video.wizard.next') ?></button>
      </div>

    <?php elseif ((int)$step === 4): ?>
      <?php
      $sched = $sched ?? ['mode' => 'always'];
      require __DIR__ . '/_schedule_fields.php';
      ?>
      <div class="video-wizard-actions">
        <button type="submit" class="btn-primary"><?= te('video.wizard.next') ?></button>
      </div>

    <?php else: ?>
      <p><?= te('video.wizard.setup.smoke_help') ?></p>
      <div class="video-wizard-actions">
        <button type="submit" class="btn-primary"><?= te('video.wizard.setup.smoke') ?></button>
      </div>
    <?php endif; ?>
  </form>
</section>
