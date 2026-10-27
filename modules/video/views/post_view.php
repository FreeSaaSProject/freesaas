<?php declare(strict_types=1); ?>
<section class="video-post-view">
  <div class="video-toolbar">
    <a class="btn btn-secondary" href="/video/posts/<?= (int)$card['ID'] ?>/edit"><?= te('core.common.edit') ?></a>
    <a class="btn btn-primary" href="/video/cameras/new?post_id=<?= (int)$card['ID'] ?>"><?= te('video.cameras.new') ?></a>
    <a class="btn btn-ghost" href="/video/wizard/train?post_id=<?= (int)$card['ID'] ?>"><?= te('video.wizard.train') ?></a>
  </div>
  <?php if (!empty($card['class_counts'])): ?>
    <p class="video-counts" style="margin-bottom:1rem">
      <?php foreach ($card['class_counts'] as $name => $cnt): ?>
        <span class="video-count-chip"><b><?= (int)$cnt ?></b> <?= e((string)$name) ?></span>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
  <?php if ($card['cameras'] === []): ?>
    <p class="video-empty"><?= te('video.empty.cameras') ?></p>
  <?php else: ?>
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
            <p class="muted"><?= e((string)$c['MODE']) ?><?php if (!empty($c['rtsp_hint'])): ?> · <?= e((string)$c['rtsp_hint']) ?><?php endif; ?></p>
            <p class="video-cam-actions">
              <a class="btn btn-sm btn-primary" href="/video/cameras/<?= (int)$c['ID'] ?>/live"><?= te('video.live.crumb') ?></a>
              <form method="post" action="/video/cameras" class="inline">
                <input type="hidden" name="action" value="enqueue_ondemand">
                <input type="hidden" name="id" value="<?= (int)$c['ID'] ?>">
                <button type="submit" class="btn btn-sm btn-secondary"><?= te('video.cameras.run_ondemand') ?></button>
              </form>
            </p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
