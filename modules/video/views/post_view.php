<?php declare(strict_types=1); ?>
<section class="video-post-view">
  <p class="video-dash-nav">
    <a class="btn" href="/video/posts/<?= (int)$card['ID'] ?>/edit"><?= te('core.common.edit') ?></a>
    <a class="btn" href="/video/cameras/new?post_id=<?= (int)$card['ID'] ?>"><?= te('video.cameras.new') ?></a>
    <a class="btn" href="/video/wizard/train?post_id=<?= (int)$card['ID'] ?>"><?= te('video.wizard.train') ?></a>
  </p>
  <?php if (!empty($card['class_counts'])): ?>
    <p class="video-counts">
      <?php foreach ($card['class_counts'] as $name => $cnt): ?>
        <span><?= e((string)$name) ?>: <strong><?= (int)$cnt ?></strong></span>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
  <div class="video-cam-grid">
    <?php foreach ($card['cameras'] as $c):
      $thumb = (string)(($c['LAST_ANNOTATED_PATH'] ?? '') ?: ($c['LAST_FRAME_PATH'] ?? ''));
      ?>
      <article class="video-cam-card">
        <a class="video-cam-thumb" href="/video/cameras/<?= (int)$c['ID'] ?>/live">
          <?php if ($thumb !== ''): ?>
            <img src="/video/media?rel=<?= e(rawurlencode($thumb)) ?>" alt="">
          <?php else: ?>
            <span class="video-cam-placeholder"><?= te('video.live.empty') ?></span>
          <?php endif; ?>
        </a>
        <div class="video-cam-body">
          <h3><a href="/video/cameras/<?= (int)$c['ID'] ?>/edit"><?= e((string)$c['NAME']) ?></a></h3>
          <p class="muted"><?= e((string)$c['MODE']) ?> · <?= e((string)($c['rtsp_hint'] ?? '')) ?></p>
          <p class="video-cam-actions">
            <a href="/video/cameras/<?= (int)$c['ID'] ?>/live"><?= te('video.live.crumb') ?></a>
            <form method="post" action="/video/cameras" class="inline">
              <input type="hidden" name="action" value="enqueue_ondemand">
              <input type="hidden" name="id" value="<?= (int)$c['ID'] ?>">
              <button type="submit"><?= te('video.cameras.run_ondemand') ?></button>
            </form>
          </p>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if ($card['cameras'] === []): ?>
      <p class="muted"><?= te('video.empty.cameras') ?></p>
    <?php endif; ?>
  </div>
</section>
