<?php
declare(strict_types=1);
/** @var int $pending */
$pending = (int)($pending ?? 0);
?>
<section class="schema-setup-gate" role="alert">
  <div class="schema-setup-banner-inner">
    <p class="eyebrow"><?= te('core.layout.schema_setup_eyebrow') ?></p>
    <strong class="schema-setup-banner-title"><?= te('core.layout.schema_setup_title') ?></strong>
    <p class="schema-setup-banner-text">
      <?= te('core.layout.schema_setup_text', ['pending' => (string)$pending]) ?>
    </p>
    <div class="schema-setup-banner-actions">
      <a class="btn-primary" href="/db"><?= te('core.layout.schema_setup_migrate') ?></a>
      <a class="btn-secondary" href="/modules"><?= te('core.layout.schema_setup_modules') ?></a>
    </div>
    <p class="schema-setup-banner-cli muted"><?= te('core.layout.schema_setup_cli') ?></p>
  </div>
</section>
