<?php
declare(strict_types=1);
/** @var array<string,mixed> $card */
/** @var int $now */
/** @var int $staleSec */
$now = $now ?? time();
$staleSec = $staleSec ?? 120;
$seen = (int)($card['LAST_SEEN'] ?? 0);
$fresh = $seen > 0 && ($now - $seen) <= $staleSec;
$cams = $card['cameras'] ?? [];
$hero = '';
foreach ($cams as $c) {
    $p = (string)(($c['LAST_ANNOTATED_PATH'] ?? '') ?: ($c['LAST_FRAME_PATH'] ?? ''));
    if ($p !== '') {
        $hero = $p;
        break;
    }
}
$id = (int)$card['ID'];
?>
<article class="video-post-card">
  <a class="video-post-hero" href="/video/posts/<?= $id ?>">
    <?php if ($hero !== ''): ?>
      <img src="/video/media?rel=<?= e(rawurlencode($hero)) ?>" alt="">
    <?php else: ?>
      <span class="video-cam-placeholder"><?= te('video.live.empty') ?></span>
    <?php endif; ?>
    <span class="video-post-hero-meta">
      <span class="video-badge <?= $fresh ? 'is-ok' : 'is-stale' ?>">
        <?= $fresh ? te('video.badge.fresh') : te('video.badge.stale') ?>
      </span>
      <span class="video-post-hero-n"><?= te('video.posts.cameras_n', ['n' => (string)count($cams)]) ?></span>
    </span>
  </a>
  <div class="video-cam-body">
    <h3>
      <a href="/video/posts/<?= $id ?>"><?= e((string)$card['NAME']) ?></a>
      <?php if (empty($card['ENABLED'])): ?>
        <span class="video-badge is-stale"><?= te('core.common.no') ?></span>
      <?php endif; ?>
    </h3>
    <?php if (!empty($card['MODEL_NAME'])): ?>
      <p class="muted video-post-model"><?= e((string)$card['MODEL_NAME']) ?></p>
    <?php endif; ?>
    <?php if (!empty($card['class_counts'])): ?>
      <p class="video-counts">
        <?php foreach ($card['class_counts'] as $name => $cnt): ?>
          <span class="video-count-chip"><b><?= (int)$cnt ?></b> <?= e((string)$name) ?></span>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
    <?php if (count($cams) > 1): ?>
      <div class="video-post-thumbs">
        <?php foreach (array_slice($cams, 0, 6) as $c):
          $thumb = (string)(($c['LAST_ANNOTATED_PATH'] ?? '') ?: ($c['LAST_FRAME_PATH'] ?? ''));
          ?>
          <a href="/video/cameras/<?= (int)$c['ID'] ?>/live" class="video-mini-thumb" title="<?= e((string)$c['NAME']) ?>">
            <?php if ($thumb !== ''): ?>
              <img src="/video/media?rel=<?= e(rawurlencode($thumb)) ?>" alt="">
            <?php else: ?>
              <span><?= e(mb_substr((string)$c['NAME'], 0, 8)) ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="video-cam-actions">
      <a class="btn btn-sm btn-primary" href="/video/posts/<?= $id ?>"><?= te('video.posts.open') ?></a>
      <a class="btn btn-sm btn-secondary" href="/video/posts/<?= $id ?>/edit"><?= te('core.common.edit') ?></a>
      <a class="btn btn-sm btn-ghost" href="/video/cameras/new?post_id=<?= $id ?>"><?= te('video.cameras.new') ?></a>
    </p>
  </div>
</article>
