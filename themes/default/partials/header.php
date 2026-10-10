<?php
declare(strict_types=1);
/** @var array{name?:string,tel?:string} $site */
/** @var list<array{label:string,href:string,current?:bool,children?:list<array{label:string,href:string,current?:bool}>}> $nav */
?>
<header class="site-header">
  <div class="site-header-inner">
    <a class="site-brand" href="/"><?= e((string)($site['name'] ?? 'FreeSaaS')) ?></a>
    <nav class="site-nav" aria-label="Main">
      <?php foreach ($nav as $item): ?>
        <?php $children = is_array($item['children'] ?? null) ? $item['children'] : []; ?>
        <?php if ($children !== []): ?>
          <span class="site-nav-group">
            <a href="<?= e((string)$item['href']) ?>"<?= !empty($item['current']) ? ' aria-current="page" class="is-current"' : '' ?>><?= e((string)$item['label']) ?></a>
            <?php foreach ($children as $child): ?>
              <a href="<?= e((string)$child['href']) ?>" class="site-nav-child<?= !empty($child['current']) ? ' is-current' : '' ?>"><?= e((string)$child['label']) ?></a>
            <?php endforeach; ?>
          </span>
        <?php else: ?>
          <?php
            $isCart = !empty($item['cart']) || (string)$item['href'] === '/cart';
            $cartCount = $isCart ? (int)($item['cart_count'] ?? (class_exists(SitesCart::class, false) ? SitesCart::count() : 0)) : 0;
          ?>
          <a href="<?= e((string)$item['href']) ?>"
            <?= !empty($item['current']) ? ' aria-current="page" class="is-current"' : '' ?>
            <?= $isCart ? ' data-cart-link data-cart-count="' . $cartCount . '"' : '' ?>
          ><?= e((string)$item['label']) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <?php if (!empty($site['tel'])): ?>
      <a class="site-tel" href="tel:<?= e(preg_replace('/\D+/', '', (string)$site['tel']) ?: '') ?>"><?= e((string)$site['tel']) ?></a>
    <?php endif; ?>
  </div>
</header>
