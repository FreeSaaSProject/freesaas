<?php
declare(strict_types=1);
/** @var string $title */
/** @var string $lead */
/** @var string $html */
/** @var bool $show_shop */
?>
<section class="site-hero">
  <h1><?= e($title) ?></h1>
  <p class="site-lead"><?= e($lead) ?></p>
  <?php if ($html !== ''): ?>
    <div class="prose"><?= safe_html($html) ?></div>
  <?php endif; ?>
  <?php if (!empty($show_shop)): ?>
    <div class="site-actions">
      <a class="btn btn-primary" href="/shop"><?= te('sites.home.cta_shop') ?></a>
    </div>
  <?php endif; ?>
</section>
