<?php
declare(strict_types=1);
$grid = (int)($coverage['grid'] ?? 8);
$cells = $coverage['cells'] ?? [];
$max = 1;
foreach ($cells as $v) {
    $max = max($max, (int)$v);
}
$ann = (string)($row['LAST_ANNOTATED_PATH'] ?? '');
$raw = (string)($row['LAST_FRAME_PATH'] ?? '');
$thumb = $ann !== '' ? $ann : $raw;
$status = (string)$row['STATUS'];
?>
<section class="video-train-session" data-session-id="<?= (int)$row['ID'] ?>"
         data-poll-url="/video/train/<?= (int)$row['ID'] ?>?format=json">
  <div class="video-train-meta">
    <span><?= te('video.train.phase') ?>: <strong><?= e((string)$row['PHASE']) ?></strong></span>
    <span><?= te('video.col.status') ?>:
      <span class="video-badge <?= $status === 'running' ? 'is-ok' : 'is-stale' ?>"><?= e($status) ?></span>
    </span>
    <span><?= te('video.train.frames') ?>: <strong><?= (int)($stats['frames'] ?? 0) ?></strong></span>
    <span><?= te('video.train.boxes') ?>: <strong><?= (int)($stats['boxes'] ?? 0) ?></strong></span>
  </div>

  <div class="video-train-actions">
    <?php if ($status === 'running'): ?>
      <form method="post" class="inline"><input type="hidden" name="action" value="pause"><button type="submit" class="btn-secondary"><?= te('video.train.pause') ?></button></form>
    <?php else: ?>
      <form method="post" class="inline"><input type="hidden" name="action" value="resume"><button type="submit" class="btn-primary"><?= te('video.train.resume') ?></button></form>
    <?php endif; ?>
    <form method="post" class="inline video-form-row" style="margin:0;align-items:flex-end">
      <input type="hidden" name="action" value="phase_solo">
      <label class="video-field"><?= te('video.train.target_class_id') ?>
        <input type="number" name="target_class_id" value="<?= (int)$row['TARGET_CLASS_ID'] ?>" min="0">
      </label>
      <button type="submit" class="btn-ghost btn-sm"><?= te('video.train.phase_solo') ?></button>
    </form>
    <form method="post" class="inline video-form-row" style="margin:0;align-items:flex-end">
      <input type="hidden" name="action" value="phase_other">
      <label class="video-field"><?= te('video.train.other_class_id') ?>
        <input type="number" name="target_class_id" value="<?= (int)$row['TARGET_CLASS_ID'] ?>" min="0">
      </label>
      <button type="submit" class="btn-ghost btn-sm"><?= te('video.train.phase_other') ?></button>
    </form>
    <form method="post" class="inline"><input type="hidden" name="action" value="finish"><button type="submit" class="btn-danger btn-sm"><?= te('video.train.finish') ?></button></form>
  </div>

  <div class="video-train-layout">
    <div class="video-train-frame-wrap">
      <?php if ($thumb !== ''): ?>
        <img class="video-live-frame video-train-frame" src="/video/media?rel=<?= e(rawurlencode($thumb)) ?>&t=<?= (int)$row['UPDATED_AT'] ?>" alt="">
      <?php else: ?>
        <p class="muted video-train-empty video-empty"><?= te('video.live.empty') ?></p>
      <?php endif; ?>
      <div class="video-coverage" style="--grid:<?= $grid ?>">
        <?php for ($i = 0; $i < $grid * $grid; $i++):
          $v = (int)($cells[$i] ?? 0);
          $pct = $max > 0 ? ($v / $max) : 0;
          ?>
          <span class="video-coverage-cell <?= $v === 0 ? 'is-poor' : '' ?>"
                style="opacity:<?= e((string)max(0.15, $pct)) ?>"
                title="<?= (int)$v ?>"></span>
        <?php endfor; ?>
      </div>
      <p class="field-help"><?= te('video.train.coverage.help') ?></p>
    </div>
    <aside class="video-train-side">
      <?php if ($projectClasses !== []): ?>
        <ul class="video-legend">
          <?php foreach ($projectClasses as $cl): ?>
            <li>
              <span class="video-swatch" style="background:<?= e((string)($cl['color'] ?? '#888')) ?>"></span>
              <?= e(VideoColors::displayName($cl)) ?>
              <span class="muted">#<?= (int)($cl['id'] ?? 0) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <form method="post" class="form">
        <input type="hidden" name="action" value="enqueue_train">
        <h3 class="video-section-title" style="margin-top:0"><?= te('video.models.train') ?></h3>
        <label class="video-field"><?= te('video.models.base_weights') ?>
          <input name="base_weights" value="yolo11n.pt">
        </label>
        <label class="video-field">epochs
          <input type="number" name="epochs" value="50">
        </label>
        <button type="submit" class="btn-primary"><?= te('video.models.enqueue_train') ?></button>
      </form>
      <?php if ($project): ?>
        <p style="margin-top:0.75rem"><a href="/video-label/<?= (int)$project['ID'] ?>/workspace"><?= te('video.train.open_label') ?></a></p>
      <?php endif; ?>
    </aside>
  </div>
</section>
