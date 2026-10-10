<?php
declare(strict_types=1);

$id = (int)Router::param('id', 0);
$meta = SitesFront::siteMeta();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    SitesCatalog::addToCartFromPost();
    redirect('/cart');
}

$product = null;
if ($id > 0 && class_exists('ProductsRepository', false)) {
    $product = ProductsRepository::find($id);
    if (!SitesCatalog::isProductVisible($product)) {
        $product = null;
    }
}

if ($product === null) {
    http_response_code(404);
    SiteTheme::render('error', [
        'site' => $meta,
        'nav' => SitesFront::nav('shop'),
        'title' => t('sites.product.not_found'),
        'message' => t('sites.product.not_found'),
        'hint' => '',
    ]);
    return;
}

$catId = (int)($product['category'] ?? 0);

SiteTheme::render('product', [
    'site' => $meta,
    'nav' => SitesFront::nav('shop'),
    'title' => (string)$product['name'],
    'product' => $product,
    'categories' => SitesCatalog::categoryNav($catId > 0 ? $catId : null),
]);
