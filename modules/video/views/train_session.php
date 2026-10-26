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
?>
<section class="video-train-session" data-session-id="<?= (int)$row['ID'] ?>"
         data-poll-url="/video/train/<?= (int)$row['ID'] ?>?format=json">
  <p class="muted">
    <?= te('video.train.phase') ?>: <strong><?= e((string)$row['PHASE']) ?></strong>
    · <?= te('video.col.status') ?>: <strong><?= e((string)$row['STATUS']) ?></strong>
    · <?= te('video.train.frames') ?>: <?= (int)($stats['frames'] ?? 0) ?>
    · <?= te('video.train.boxes') ?>: <?= (int)($stats['boxes'] ?? 0) ?>
  </p>

  <p class="video-dash-nav">
    <?php if ((string)$row['STATUS'] === 'running'): ?>
      <form method="post" class="inline"><input type="hidden" name="action" value="pause"><button type="submit"><?= te('video.train.pause') ?></button></form>
    <?php else: ?>
      <form method="post" class="inline"><input type="hidden" name="action" value="resume"><button type="submit"><?= te('video.train.resume') ?></button></form>
    <?php endif; ?>
    <form method="post" class="inline">
      <input type="hidden" name="action" value="phase_other">
      <input type="number" name="target_class_id" value="<?= (int)$row['TARGET_CLASS_ID'] ?>" min="0" style="width:4rem">
      <button type="submit"><?= te('video.train.phase_other') ?></button>
    </form>
    <form method="post" class="inline">
      <input type="hidden" name="action" value="phase_solo">
      <input type="number" name="target_class_id" value="<?= (int)$row['TARGET_CLASS_ID'] ?>" min="0" style="width:4rem">
      <button type="submit"><?= te('video.train.phase_solo') ?></button>
    </form>
    <form method="post" class="inline"><input type="hidden" name="action" value="finish"><button type="submit"><?= te('video.train.finish') ?></button></form>
  </p>

  <div class="video-train-layout">
    <div class="video-train-frame-wrap">
      <?php if ($thumb !== ''): ?>
        <img class="video-live-frame video-train-frame" src="/video/media?rel=<?= e(rawurlencode($thumb)) ?>&t=<?= (int)$row['UPDATED_AT'] ?>" alt="">
      <?php else: ?>
        <p class="muted video-train-empty"><?= te('video.live.empty') ?></p>
      <?php endif; ?>
      <div class="video-coverage" style="--grid:<?= $grid ?>">
        <?php for ($i = 0; $i < $grid * $grid; $i++):
          $v = (int)($cells[$i] ?? 0);
          $pct = $max > 0 ? ($v / $max) : 0;
          $poor = $v === 0;
          ?>
          <span class="video-coverage-cell <?= $poor ? 'is-poor' : '' ?>"
                style="opacity:<?= e((string)max(0.15, $pct)) ?>"
                title="<?= (int)$v ?>"></span>
        <?php endfor; ?>
      </div>
      <p class="field-help"><?= te('video.train.coverage.help') ?></p>
    </div>
    <div>
      <?php if ($projectClasses !== []): ?>
        <ul class="video-legend">
          <?php foreach ($projectClasses as $cl): ?>
            <li>
              <span class="video-swatch" style="background:<?= e((string)($cl['color'] ?? '#888')) ?>"></span>
              <?= e(VideoColors::displayName($cl)) ?>
              <span class="muted">(<?= e((string)($cl['name'] ?? '')) ?> #<?= (int)($cl['id'] ?? 0) ?>)</span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <form method="post" class="form">
        <input type="hidden" name="action" value="enqueue_train">
        <h3><?= te('video.models.train') ?></h3>
        <label><?= te('video.models.base_weights') ?> <input name="base_weights" value="yolo11n.pt"></label>
        <label>epochs <input type="number" name="epochs" value="50"></label>
        <button type="submit"><?= te('video.models.enqueue_train') ?></button>
      </form>
      <?php if ($project): ?>
        <p><a href="/video-label/<?= (int)$project['ID'] ?>/workspace"><?= te('video.train.open_label') ?></a></p>
      <?php endif; ?>
    </div>
  </div>
</section>
