<?php
declare(strict_types=1);
/** @var string $brand */
/** @var string $instance */
/** @var string $userName */
/** @var array $widgets */
?>
<section class="widgets" aria-label="<?= te('core.home.widgets_aria') ?>">
  <div class="widgets-toolbar">
    <a class="btn-ghost widgets-customize" href="/dashboard" title="<?= te('core.dashboard.customize') ?>">
      <svg class="widgets-customize-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="3"></circle>
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
      </svg>
      <span><?= te('core.dashboard.customize') ?></span>
    </a>
  </div>
  <?php if (!$widgets): ?>
    <p class="muted widgets-empty"><?= te('core.dashboard.empty') ?></p>
  <?php endif; ?>
  <?php foreach ($widgets as $w): ?>
    <?php
      $size = (string)($w['size'] ?? 'sm');
      if (!in_array($size, ['sm', 'md', 'lg'], true)) {
          $size = 'sm';
      }
      $tone = (string)($w['tone'] ?? 'neutral');
      $wTitle = trim((string)($w['title'] ?? ''));
    ?>
    <article class="widget widget-<?= e($size) ?> tone-<?= e($tone) ?><?= ($w['id'] ?? '') === 'core.command' ? ' widget-command' : '' ?>">
      <?php if ($wTitle !== ''): ?>
        <h2><?= e($wTitle) ?></h2>
      <?php endif; ?>
      <?php if (!empty($w['html'])): ?>
        <div class="widget-body"><?= $w['html'] /* intentional trusted HTML from module partials */ ?></div>
      <?php elseif (isset($w['body']) && (string)$w['body'] !== ''): ?>
        <p><?= e((string)$w['body']) ?></p>
      <?php endif; ?>
      <?php if (!empty($w['href']) || !empty($w['href_mobile'])): ?>
        <?php
          $hrefDesk = (string)($w['href'] ?? $w['href_mobile'] ?? '');
          $hrefMob = (string)($w['href_mobile'] ?? $w['href'] ?? '');
        ?>
        <?php if ($hrefDesk !== '' && $hrefMob !== '' && $hrefDesk !== $hrefMob): ?>
          <a class="widget-link widget-link-wide" href="<?= e($hrefDesk) ?>"><?= te('core.home.open') ?></a>
          <a class="widget-link widget-link-narrow" href="<?= e($hrefMob) ?>"><?= te('core.home.open') ?></a>
        <?php elseif ($hrefDesk !== ''): ?>
          <a class="widget-link" href="<?= e($hrefDesk) ?>"><?= te('core.home.open') ?></a>
        <?php endif; ?>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>
