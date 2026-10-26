<?php declare(strict_types=1); ?>
<section>
  <p class="video-dash-nav">
    <a class="btn" href="/video/posts/new"><?= te('video.posts.new') ?></a>
    <a class="btn" href="/video/wizard/setup"><?= te('video.wizard.setup') ?></a>
    <a class="btn" href="/video/wizard/train"><?= te('video.wizard.train') ?></a>
  </p>
  <form method="get" class="filters">
    <input type="search" name="q" value="<?= e((string)$q) ?>" placeholder="<?= te('video.posts.search') ?>">
    <button type="submit"><?= te('video.cameras.filter') ?></button>
  </form>
  <div class="video-cam-grid">
    <?php foreach ($cards as $card): ?>
      <article class="video-cam-card video-post-card">
        <div class="video-cam-body">
          <h3>
            <a href="/video/posts/<?= (int)$card['ID'] ?>"><?= e((string)$card['NAME']) ?></a>
            <span class="video-badge <?= !empty($card['ENABLED']) ? 'is-ok' : 'is-stale' ?>">
              <?= !empty($card['ENABLED']) ? te('core.common.yes') : te('core.common.no') ?>
            </span>
          </h3>
          <p class="muted">
            <?= te('video.posts.cameras_n', ['n' => (string)(int)$card['CAMERA_COUNT']]) ?>
            <?php if ($card['MODEL_NAME'] !== ''): ?> · <?= e((string)$card['MODEL_NAME']) ?><?php endif; ?>
          </p>
          <?php if (!empty($card['class_counts'])): ?>
            <p class="video-counts">
              <?php foreach ($card['class_counts'] as $name => $cnt): ?>
                <span><?= e((string)$name) ?>: <strong><?= (int)$cnt ?></strong></span>
              <?php endforeach; ?>
            </p>
          <?php endif; ?>
          <div class="video-post-thumbs">
            <?php foreach (array_slice($card['cameras'], 0, 4) as $c):
              $thumb = (string)($c['LAST_ANNOTATED_PATH'] ?: $c['LAST_FRAME_PATH'] ?? '');
              ?>
              <a href="/video/cameras/<?= (int)$c['ID'] ?>/live" class="video-mini-thumb">
                <?php if ($thumb !== ''): ?>
                  <img src="/video/media?rel=<?= e(rawurlencode($thumb)) ?>" alt="">
                <?php else: ?>
                  <span><?= e((string)$c['NAME']) ?></span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
          <p class="video-cam-actions">
            <a href="/video/posts/<?= (int)$card['ID'] ?>"><?= te('video.posts.open') ?></a>
            <a href="/video/posts/<?= (int)$card['ID'] ?>/edit"><?= te('core.common.edit') ?></a>
            <a href="/video/cameras/new?post_id=<?= (int)$card['ID'] ?>"><?= te('video.cameras.new') ?></a>
          </p>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if ($cards === []): ?>
      <p class="muted"><?= te('video.empty.posts') ?></p>
    <?php endif; ?>
  </div>
</section>
