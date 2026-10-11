<?php
declare(strict_types=1);
/** @var string $title */
/** @var string $message */
/** @var string|null $hint */
/** @var bool $schema */
/** @var string|null $detail */
$schema = !empty($schema);
$detail = (string)($detail ?? '');
?>
<section class="page-head">
  <p class="eyebrow"><?= te($schema ? 'core.error.schema_eyebrow' : 'core.error.eyebrow') ?></p>
  <h1><?= e($title ?? t('core.error.title')) ?></h1>
  <p class="lede"><?= e($message ?? '') ?></p>
  <?php if ($schema): ?>
    <div class="actions">
      <a class="btn-primary" href="/db"><?= te('core.error.schema_goto_migrate') ?></a>
      <a class="btn-secondary" href="/modules"><?= te('core.error.schema_goto_modules') ?></a>
    </div>
    <p class="muted"><?= te('core.error.schema_cli') ?></p>
    <?php if ($detail !== ''): ?>
      <p class="muted"><code><?= e($detail) ?></code></p>
    <?php endif; ?>
  <?php elseif (!empty($hint)): ?>
    <p class="muted"><?= e($hint) ?></p>
  <?php endif; ?>
  <p><a href="/">← <?= te('core.menu.home') ?></a></p>
</section>
