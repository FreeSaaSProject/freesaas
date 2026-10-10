<?php
declare(strict_types=1);
/** @var string $title */
/** @var list<array<string,mixed>> $items */
/** @var list<array{id:int,name:string,href:string,current:bool}> $categories */
/** @var array{type?:string,message?:string}|null $flash */
/** @var string $ret */
$categories = $categories ?? [];
$ret = $ret ?? '/shop';
?>
<section class="site-section">
  <h1><?= e($title) ?></h1>
  <?php if (!empty($flash['message'])): ?>
    <p class="flash flash-<?= e((string)($flash['type'] ?? 'ok')) ?>"><?= e((string)$flash['message']) ?></p>
  <?php endif; ?>

  <?php if ($categories !== []): ?>
    <nav class="shop-cats" aria-label="<?= te('sites.shop.nav_aria') ?>">
      <?php foreach ($categories as $cat): ?>
        <a class="shop-cat<?= !empty($cat['current']) ? ' is-current' : '' ?>" href="<?= e((string)$cat['href']) ?>"><?= e((string)$cat['name']) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <?php if ($items === []): ?>
    <p class="muted"><?= te('sites.shop.empty') ?></p>
  <?php else: ?>
    <div class="product-grid">
      <?php foreach ($items as $p): ?>
        <article class="product-card">
          <h2><a href="/shop/<?= (int)$p['id'] ?>"><?= e((string)$p['name']) ?></a></h2>
          <p class="product-price"><?= e(number_format((float)$p['price'], 0, '.', ' ')) ?></p>
          <form method="post" action="/cart" class="js-cart-add">
            <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="ret" value="<?= e($ret) ?>">
            <button class="btn btn-primary cd-add-to-cart" type="submit" name="add" value="1" data-product-id="<?= (int)$p['id'] ?>"><?= te('sites.shop.add') ?></button>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
