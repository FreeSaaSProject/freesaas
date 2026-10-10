<?php
declare(strict_types=1);
/** @var array<string,mixed> $product */
/** @var list<array{id:int,name:string,href:string,current:bool}> $categories */
$categories = $categories ?? [];
$back = '/shop';
$catId = (int)($product['category'] ?? 0);
if ($catId > 0) {
    $back = '/shop/c/' . $catId;
}
?>
<article class="site-section">
  <?php if ($categories !== []): ?>
    <nav class="shop-cats" aria-label="<?= te('sites.shop.nav_aria') ?>">
      <?php foreach ($categories as $cat): ?>
        <a class="shop-cat<?= !empty($cat['current']) ? ' is-current' : '' ?>" href="<?= e((string)$cat['href']) ?>"><?= e((string)$cat['name']) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
  <p><a href="<?= e($back) ?>"><?= te('sites.product.back') ?></a></p>
  <h1><?= e((string)$product['name']) ?></h1>
  <p class="product-price"><?= te('sites.shop.price') ?>: <?= e(number_format((float)$product['price'], 0, '.', ' ')) ?></p>
  <?php if (!empty($product['description'])): ?>
    <div class="prose"><?= safe_html((string)$product['description']) ?></div>
  <?php elseif (!empty($product['descr_proc'])): ?>
    <div class="prose"><?= safe_html((string)$product['descr_proc']) ?></div>
  <?php endif; ?>
  <form method="post" action="/cart" class="site-actions js-cart-add">
    <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
    <input type="hidden" name="ret" value="/shop/<?= (int)$product['id'] ?>">
    <button class="btn btn-primary cd-add-to-cart" type="submit" name="add" value="1" data-product-id="<?= (int)$product['id'] ?>"><?= te('sites.shop.add') ?></button>
  </form>
</article>
