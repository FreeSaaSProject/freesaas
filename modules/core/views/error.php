<?php
/** @var string $title */
/** @var string $message */
/** @var string|null $hint */
?>
<section class="page-head">
  <p class="eyebrow"><?= te('core.error.eyebrow') ?></p>
  <h1><?= e($title ?? t('core.error.title')) ?></h1>
  <p class="lede"><?= e($message ?? '') ?></p>
  <?php if (!empty($hint)): ?>
    <p class="muted"><?= e($hint) ?></p>
  <?php endif; ?>
  <p><a href="/">← <?= te('core.menu.home') ?></a></p>
</section>
