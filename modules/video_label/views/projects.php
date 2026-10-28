<?php declare(strict_types=1); ?>
<section class="video-label-projects">
  <div class="video-toolbar">
    <a class="btn-primary" href="/video-label/new"><?= te('video_label.projects.new') ?></a>
    <span class="spacer"></span>
    <span class="muted"><?= (int)count($rows) ?></span>
  </div>

  <?php if ($rows === []): ?>
    <p class="muted"><?= te('video_label.empty.projects') ?></p>
  <?php else: ?>
    <div class="video-label-project-grid">
      <?php foreach ($rows as $r):
        $classes = json_decode((string)($r['CLASSES_JSON'] ?? ''), true) ?: [];
        if (!is_array($classes)) {
            $classes = [];
        }
        $nImg = (int)($r['IMAGE_COUNT'] ?? 0);
        $nCls = count($classes);
        ?>
        <article class="video-label-project-card">
          <a class="video-label-project-main" href="/video-label/<?= (int)$r['ID'] ?>/workspace">
            <h3><?= e((string)$r['NAME']) ?></h3>
            <p class="muted">
              <?= te('video_label.projects.stat_images', ['n' => (string)$nImg]) ?>
              · <?= te('video_label.projects.stat_classes', ['n' => (string)$nCls]) ?>
            </p>
            <?php if ($classes !== []): ?>
              <ul class="video-label-class-chips">
                <?php foreach (array_slice($classes, 0, 8) as $c):
                  if (!is_array($c)) {
                      continue;
                  }
                  $color = (string)($c['color'] ?? '#888');
                  $label = (string)($c['label'] ?? $c['name'] ?? '');
                  ?>
                  <li><span class="swatch" style="background:<?= e($color) ?>"></span><?= e($label) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </a>
          <div class="video-label-project-actions">
            <a class="btn-primary btn-sm" href="/video-label/<?= (int)$r['ID'] ?>/workspace"><?= te('video_label.projects.open') ?></a>
            <a class="btn-ghost btn-sm" href="/video-label/<?= (int)$r['ID'] ?>/edit"><?= te('core.common.edit') ?></a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
