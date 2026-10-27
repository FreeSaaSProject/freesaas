<?php declare(strict_types=1); ?>
<section class="video-wizard">
  <?php
  $trainSteps = [
    1 => 'video.wizard.train.step1',
    2 => 'video.wizard.train.step2',
    3 => 'video.wizard.train.step3',
    4 => 'video.wizard.train.step4',
  ];
  ?>
  <ol class="video-wizard-steps">
    <?php foreach ($trainSteps as $i => $key): ?>
      <li class="<?= $i === (int)$step ? 'is-current' : ($i < (int)$step ? 'is-done' : '') ?>">
        <span class="video-step-num"><?= (int)$i ?></span>
        <?= te($key) ?>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php if (!empty($error)): ?><p class="error"><?= e((string)$error) ?></p><?php endif; ?>

  <form method="post" class="form video-form video-wizard-panel">
    <input type="hidden" name="step" value="<?= (int)$step ?>">
    <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
    <input type="hidden" name="post_id" value="<?= (int)$postId ?>">

    <?php if ((int)$step === 1): ?>
      <div class="video-form-row">
        <label class="video-field"><?= te('video.col.post') ?>
          <select name="post_id" onchange="location.href='/video/wizard/train?step=1&post_id='+this.value">
            <option value="0">—</option>
            <?php foreach ($posts as $p): ?>
              <option value="<?= (int)$p['ID'] ?>" <?= (int)$postId === (int)$p['ID'] ? 'selected' : '' ?>><?= e((string)$p['NAME']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="video-field"><?= te('video.col.camera') ?>
          <select name="camera_id" required>
            <?php foreach ($cameras as $c): ?>
              <option value="<?= (int)$c['ID'] ?>"><?= e((string)$c['NAME']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="video-form-row">
        <label class="video-field"><?= te('video.models.train_project') ?>
          <select name="project_id" required>
            <option value="0">—</option>
            <?php foreach ($projects as $p): ?>
              <option value="<?= (int)$p['ID'] ?>"><?= e((string)$p['NAME']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="video-field"><?= te('video.train.target_class_id') ?>
          <input type="number" name="target_class_id" value="0" min="0">
        </label>
        <label class="video-field"><?= te('video.train.interval_ms') ?>
          <input type="number" name="interval_ms" value="500" min="100">
        </label>
      </div>
      <p class="field-help"><?= te('video.wizard.train.solo_help') ?></p>
      <div class="video-wizard-actions">
        <button type="submit" class="btn-primary"><?= te('video.wizard.next') ?></button>
      </div>

    <?php elseif ((int)$step === 2): ?>
      <p><?= te('video.wizard.train.coverage_help') ?></p>
      <?php
      $grid = (int)($coverage['grid'] ?? 8);
      $cells = $coverage['cells'] ?? [];
      $max = 1;
      foreach ($cells as $v) {
          $max = max($max, (int)$v);
      }
      ?>
      <div class="video-coverage video-coverage-solo" style="--grid:<?= $grid ?>">
        <?php for ($i = 0; $i < $grid * $grid; $i++):
          $v = (int)($cells[$i] ?? 0);
          $pct = $max > 0 ? ($v / $max) : 0;
          ?>
          <span class="video-coverage-cell <?= $v === 0 ? 'is-poor' : '' ?>"
                style="opacity:<?= e((string)max(0.15, $pct)) ?>" title="<?= $v ?>"></span>
        <?php endfor; ?>
      </div>
      <p><a href="/video/train/<?= (int)$sessionId ?>"><?= te('video.train.live_link') ?></a></p>
      <label class="video-field"><?= te('video.train.other_class_id') ?>
        <input type="number" name="other_class_id" value="1" min="0">
      </label>
      <p class="field-help"><?= te('video.wizard.train.other_help') ?></p>
      <div class="video-wizard-actions">
        <button type="submit" class="btn-primary"><?= te('video.wizard.next') ?></button>
      </div>

    <?php elseif ((int)$step === 3): ?>
      <p><?= te('video.wizard.train.finish_help') ?></p>
      <div class="video-wizard-actions">
        <button type="submit" class="btn-primary"><?= te('video.train.finish') ?></button>
      </div>

    <?php else: ?>
      <label><input type="checkbox" name="do_train" value="1" checked> <?= te('video.wizard.train.do_first_train') ?></label>
      <div class="video-wizard-actions">
        <button type="submit" class="btn-primary"><?= te('video.wizard.finish') ?></button>
      </div>
    <?php endif; ?>
  </form>
</section>
