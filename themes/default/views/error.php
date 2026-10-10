<?php
declare(strict_types=1);
/** @var string $title */
/** @var string $message */
/** @var string $hint */
?>
<section class="site-section">
  <h1><?= e($title ?? t('sites.error.title')) ?></h1>
  <p class="site-lead"><?= e($message ?? '') ?></p>
  <?php if (!empty($hint)): ?>
    <p class="muted"><code><?= e((string)$hint) ?></code></p>
  <?php endif; ?>
  <p><a class="btn" href="/"><?= te('sites.nav.home') ?></a></p>
</section>
