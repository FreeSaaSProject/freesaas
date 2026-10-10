<?php
declare(strict_types=1);

$meta = SitesFront::siteMeta();
$flash = flash_take();
$categoryId = (int)Router::param('id', 0);
$category = null;
if ($categoryId > 0 && class_exists('CategoriesRepository', false)) {
    $category = CategoriesRepository::find($categoryId);
    if ($category && !empty($category['hidden'])) {
        $category = null;
    }
    if ($category !== null && !SitesCatalog::categoryAllowed($categoryId)) {
        $category = null;
    }
}

if ($category === null) {
    http_response_code(404);
    SiteTheme::render('error', [
        'site' => $meta,
        'nav' => SitesFront::nav('shop'),
        'title' => t('sites.shop.category_not_found'),
        'message' => t('sites.shop.category_not_found'),
        'hint' => '',
    ]);
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    SitesCatalog::addToCartFromPost();
    redirect('/shop/c/' . $categoryId);
}

$items = SitesCatalog::listProducts([
    'category' => $categoryId,
    'limit' => 200,
])['items'];

SiteTheme::render('shop', [
    'site' => $meta,
    'nav' => SitesFront::nav('shop'),
    'title' => (string)$category['name'],
    'items' => $items,
    'categories' => SitesCatalog::categoryNav($categoryId),
    'category' => $category,
    'flash' => $flash,
    'ret' => '/shop/c/' . $categoryId,
]);
