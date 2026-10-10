<?php
declare(strict_types=1);
$count = class_exists(SitesCart::class, false) ? SitesCart::count() : 0;
$total = class_exists(SitesCart::class, false) ? SitesCart::total() : 0.0;
$aria = t('sites.cart.fab_aria', ['n' => (string)$count]);
?>
<a href="/cart"
   class="site-cart-fab<?= $count < 1 ? ' is-empty' : '' ?>"
   data-cart-fab
   data-cart-count="<?= (int)$count ?>"
   data-cart-total="<?= e(number_format($total, 0, '.', '')) ?>"
   aria-label="<?= e($aria) ?>">
  <span class="site-cart-fab__glyph" aria-hidden="true">C</span>
  <span class="site-cart-fab__count" data-cart-fab-count><?= (int)$count ?></span>
</a>
