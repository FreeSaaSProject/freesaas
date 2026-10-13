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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guest_phone'])) {
    $result = SitesGuest::setPhone((string)($_POST['phone'] ?? ''));
    if (!$result['ok']) {
        flash_set('error', $result['error'] !== '' ? $result['error'] : t('sites.cart.err_phone'));
    } elseif ($result['blocked']) {
        flash_set('error', t('sites.cart.err_blocked'));
    }
    redirect('/cart');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guest_phone_clear'])) {
    SitesGuest::clear();
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
// FREE_TICKET meta is host + likespa EventBus — not only booking wizard carts.
$bookingMeta = class_exists(SitesBooking::class, false)
    ? SitesBooking::cartMeta()
    : [
        'free_ticket_threshold' => 0,
        'free_ticket_eligible' => false,
        'free_ticket_hint' => '',
        'free_ticket_not_today' => '',
    ];
$bookingConfirm = SitesCart::takeBookingConfirm();

$guestPhone = SitesGuest::phone();
$guestBlocked = SitesGuest::isBlocked();
$guestReady = SitesGuest::canProceed();
// Phone gate for any non-empty cart (booking wizard or simple checkout).
$needPhone = SitesCart::lines() !== [] && !$guestReady && !$guestBlocked;
$showCheckout = SitesCart::lines() !== [] && $guestReady;

$cartPrepare = EventBus::emit('sites.cart.prepare', [
    'lines' => SitesCart::lines(),
    'total' => SitesCart::total(),
    'guest_phone' => $guestPhone,
    'guest_ready' => $guestReady,
    'guest_blocked' => $guestBlocked,
    'show_checkout' => $showCheckout,
    'html' => '',
]);
$cartExtraHtml = is_array($cartPrepare) ? (string)($cartPrepare['html'] ?? '') : '';

SiteTheme::render('cart', [
    'site' => $meta,
    'nav' => SitesFront::nav('cart'),
    'title' => t('sites.cart.title'),
    'lines' => SitesCart::lines(),
    'total' => SitesCart::total(),
    'flash' => $flash,
    'booking_confirm' => $bookingConfirm,
    'orders_ok' => class_exists('OrdersRepository', false) && Database::tableExists('base_orders'),
    'booking_ok' => $bookingOk && $showCheckout,
    'booking_product_ids' => ($bookingOk && $showCheckout) ? SitesBooking::productIdsFlat() : [],
    'booking_date' => date('Y-m-d', time() + 86400),
    'booking_meta' => $bookingMeta,
    'guest_phone' => $guestPhone,
    'guest_blocked' => $guestBlocked,
    'guest_ready' => $guestReady,
    'need_phone' => $needPhone,
    'show_checkout' => $showCheckout,
    'cart_extra_html' => $cartExtraHtml,
]);
