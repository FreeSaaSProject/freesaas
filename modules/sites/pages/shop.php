<?php
declare(strict_types=1);

$meta = SitesFront::siteMeta();
$flash = flash_take();
$categoryId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    SitesCatalog::addToCartFromPost();
    $ret = trim((string)($_POST['ret'] ?? '/shop'));
    if ($ret === '' || $ret[0] !== '/') {
        $ret = '/shop';
    }
    redirect($ret);
}

$items = [];
$category = null;
if (class_exists('ProductsRepository', false) && Database::tableExists('base_products')) {
    $items = SitesCatalog::listProducts([
        'category' => null,
        'limit' => 200,
    ])['items'];
}

SiteTheme::render('shop', [
    'site' => $meta,
    'nav' => SitesFront::nav('shop'),
    'title' => t('sites.shop.title'),
    'items' => $items,
    'categories' => SitesCatalog::categoryNav(null),
    'category' => $category,
    'flash' => $flash,
    'ret' => '/shop',
]);
