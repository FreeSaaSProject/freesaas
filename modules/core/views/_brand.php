<?php
/** @var string $brand Product name (FreeSaaS) */
/** @var string $instance Company display name */
/** @var string $brandClass CSS class: rail-brand | topbar-brand */
$companyLabel = $instance !== '' ? $instance : $brand;
$logoVariant = $brandClass === 'topbar-brand' ? SystemLogo::VARIANT_TOPBAR : SystemLogo::VARIANT_RAIL;
$logoUrl = class_exists('SystemLogo', false) ? SystemLogo::url($logoVariant) : null;
$showBy = $instance !== '' || $logoUrl;
?>
<a class="<?= e($brandClass) ?><?= $logoUrl ? ' has-logo' : '' ?>" href="/" title="<?= e($companyLabel) ?>">
  <?php if ($logoUrl): ?>
    <img class="brand-logo" src="<?= e($logoUrl) ?>" alt="<?= e($companyLabel) ?>" decoding="async">
  <?php else: ?>
    <span class="brand-company"><?= e($companyLabel) ?></span>
  <?php endif; ?>
  <?php if ($showBy): ?>
    <span class="brand-by"><?= te('core.layout.by') ?> <span class="brand-platform"><?= e($brand) ?></span></span>
  <?php endif; ?>
</a>
