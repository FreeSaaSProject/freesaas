<?php
declare(strict_types=1);
/** @var array<string,mixed> $promo */
/** @var string $html */
$html = (string)($html ?? '');
?>
<?php if ($html !== ''): ?>
  <div class="promo-body"><?= $html ?></div>
<?php else: ?>
<section class="site-section">
  <h1><?= e((string)($promo['descr'] ?? t('sites.promo.title'))) ?></h1>
  <p class="muted"><code><?= e((string)($promo['code'] ?? '')) ?></code></p>
  <?php if (!empty($promo['meta_descr'])): ?>
    <p class="site-lead"><?= e((string)$promo['meta_descr']) ?></p>
  <?php endif; ?>
  <div class="site-actions">
    <a class="btn btn-primary" href="/shop"><?= te('sites.home.cta_shop') ?></a>
  </div>
</section>
<?php endif; ?>
