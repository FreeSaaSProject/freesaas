<?php
declare(strict_types=1);

$meta = SitesFront::siteMeta();
$flash = flash_take();
$ajax = SitesCart::wantsAjax();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    $added = SitesCatalog::addToCartFromPost();
    if ($ajax) {
        $snap = SitesCart::snapshot();
        if ($added === null) {
            SitesCart::emitJson([
                'ok' => false,
                'error' => t('sites.cart.err_product'),
                'count' => $snap['count'],
                'total' => $snap['total'],
                'lines' => $snap['lines'],
            ], 400);
        }
        SitesCart::emitJson([
            'ok' => true,
            'message' => t('sites.cart.added'),
            'product' => $added,
            'count' => $snap['count'],
            'total' => $snap['total'],
            'lines' => $snap['lines'],
            'checkout_label' => t('sites.cart.checkout_short'),
            'cart_href' => '/cart',
        ]);
    }
    $ret = trim((string)($_POST['ret'] ?? '/cart'));
    if ($ret === '' || ($ret[0] !== '/' && $ret[0] !== '#')) {
        $ret = '/cart';
    }
    // Allow /#PRICE style return (path + hash).
    if ($ret[0] === '#') {
        $ret = '/' . $ret;
    }
    redirect($ret);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove'])) {
    SitesCart::remove((int)($_POST['product_id'] ?? 0));
    if ($ajax) {
        $snap = SitesCart::snapshot();
        SitesCart::emitJson([
            'ok' => true,
            'count' => $snap['count'],
            'total' => $snap['total'],
            'lines' => $snap['lines'],
        ]);
    }
    redirect('/cart');
}

if ($ajax && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $snap = SitesCart::snapshot();
    SitesCart::emitJson([
        'ok' => true,
        'count' => $snap['count'],
        'total' => $snap['total'],
        'lines' => $snap['lines'],
    ]);
}

$bookingOk = SitesBooking::needsBooking();
$bookingMeta = $bookingOk ? SitesBooking::cartMeta() : [
    'free_ticket_threshold' => 0,
    'free_ticket_eligible' => false,
    'free_ticket_hint' => '',
    'free_ticket_not_today' => '',
];

SiteTheme::render('cart', [
    'site' => $meta,
    'nav' => SitesFront::nav('cart'),
    'title' => t('sites.cart.title'),
    'lines' => SitesCart::lines(),
    'total' => SitesCart::total(),
    'flash' => $flash,
    'orders_ok' => class_exists('OrdersRepository', false) && Database::tableExists('base_orders'),
    'booking_ok' => $bookingOk,
    'booking_product_ids' => $bookingOk ? SitesBooking::productIdsFlat() : [],
    'booking_date' => date('Y-m-d', time() + 86400),
    'booking_meta' => $bookingMeta,
]);
