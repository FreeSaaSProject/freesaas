<?php declare(strict_types=1); ?>
<section>
  <div class="video-toolbar">
    <a class="btn-primary" href="/video/posts/new"><?= te('video.posts.new') ?></a>
    <a class="btn-secondary" href="/video/wizard/setup"><?= te('video.wizard.setup') ?></a>
    <a class="btn-ghost" href="/video/wizard/train"><?= te('video.wizard.train') ?></a>
  </div>
  <form method="get" class="filters">
    <input type="search" name="q" value="<?= e((string)$q) ?>" placeholder="<?= te('video.posts.search') ?>">
    <button type="submit" class="btn-secondary"><?= te('video.cameras.filter') ?></button>
  </form>
  <?php if ($cards === []): ?>
    <p class="video-empty"><?= te('video.empty.posts') ?></p>
  <?php else:
    $now = time();
    $staleSec = 120;
    ?>
    <div class="video-cam-grid">
      <?php foreach ($cards as $card):
        require __DIR__ . '/_post_card.php';
      endforeach; ?>
    </div>
  <?php endif; ?>
</section>
