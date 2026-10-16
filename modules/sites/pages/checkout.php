<?php
declare(strict_types=1);

$meta = SitesFront::siteMeta();

if (!class_exists('OrdersRepository', false) || !Database::tableExists('base_orders')) {
    flash_set('error', t('sites.cart.err_orders'));
    redirect('/cart');
}

$lines = SitesCart::orderLines();
if ($lines === []) {
    flash_set('error', t('sites.cart.err_empty'));
    redirect('/cart');
}

// Prefer session guest phone; fall back to POST (legacy / simple forms).
$phone = SitesGuest::phone();
if ($phone === '') {
    $phone = SitesGuest::normalizePhone((string)($_POST['phone'] ?? ''));
    if ($phone !== '') {
        SitesGuest::setPhone($phone);
    }
}
if ($phone === '' || !SitesGuest::canProceed()) {
    if (SitesGuest::isBlocked()) {
        flash_set('error', t('sites.cart.err_blocked'));
    } else {
        flash_set('error', t('sites.cart.err_phone'));
    }
    redirect('/cart');
}

if (class_exists(ClientsRepository::class, false)) {
    $client = ClientsRepository::findByPhoneDigits($phone);
    if ($client !== null && !ClientsRepository::canBookOnline($client)) {
        flash_set('error', t('sites.cart.err_blocked'));
        redirect('/cart');
    }
}

$mail = trim((string)($_POST['mail'] ?? ''));
$comment = trim((string)($_POST['comment'] ?? ''));
$operatorId = (int)($_POST['operator_id'] ?? 0);
$dtStart = (int)($_POST['dt_start'] ?? 0);
$needsBooking = SitesBooking::needsBooking();
// FREE_TICKET is LikeSpa-only (sites.cart.booking EventBus). Core checkout never requires it.
$bookingMeta = $needsBooking ? SitesBooking::cartMeta() : [
    'free_ticket_threshold' => 0,
    'free_ticket_eligible' => false,
];
$freeTicket = 0;
if ((int)($bookingMeta['free_ticket_threshold'] ?? 0) > 0 && !empty($_POST['free_ticket'])) {
    $freeTicket = 1;
}

$payload = EventBus::emit('sites.checkout.before', [
    'lines' => $lines,
    'phone' => $phone,
    'mail' => $mail,
    'comment' => $comment,
    'operator_id' => $operatorId,
    'dt_start' => $dtStart,
    'free_ticket' => $freeTicket,
    'booking' => $needsBooking,
    'error' => '',
]);
if (trim((string)($payload['error'] ?? '')) !== '') {
    flash_set('error', (string)$payload['error']);
    redirect('/cart');
}

$orderLines = is_array($payload['lines'] ?? null) ? $payload['lines'] : $lines;
$phone = SitesGuest::normalizePhone((string)($payload['phone'] ?? $phone)) ?: $phone;
$mail = (string)($payload['mail'] ?? $mail);
$comment = (string)($payload['comment'] ?? $comment);
$operatorId = (int)($payload['operator_id'] ?? $operatorId);
$dtStart = (int)($payload['dt_start'] ?? $dtStart);
$freeTicket = !empty($payload['free_ticket']) ? 1 : 0;

if ($needsBooking) {
    if ($dtStart < 1) {
        flash_set('error', t('sites.cart.err_slot'));
        redirect('/cart');
    }
    $dayStart = (int)mktime(0, 0, 0, (int)date('n', $dtStart), (int)date('j', $dtStart), (int)date('Y', $dtStart));
    if ($dayStart < (int)mktime(0, 0, 0)) {
        flash_set('error', t('sites.cart.err_date_past'));
        redirect('/cart');
    }
    $productIdsFlat = SitesBooking::productIdsFlat();
    if ($operatorId < 1) {
        // «Any master»: assign someone free at this slot.
        $operatorId = SitesBooking::resolveOperatorForSlot(
            date('Y-m-d', $dtStart),
            $productIdsFlat,
            $dtStart
        );
    }
    if ($operatorId < 1) {
        flash_set('error', t('sites.cart.err_slot'));
        redirect('/cart');
    }
    try {
        $booked = BookingBook::createOrder([
            'phone' => $phone,
            'mail' => $mail,
            'comment' => $comment,
            'product_ids' => $productIdsFlat,
            'cart_lines' => $orderLines,
            'operator_id' => $operatorId,
            'dt_start' => $dtStart,
            'free_ticket' => $freeTicket,
            'client_id' => SitesGuest::clientId(),
            'source' => 'site',
            'lead_sec' => SitesBooking::leadSec(),
        ]);
        $orderId = (int)$booked['order_id'];
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect('/cart');
    }

    EventBus::emit('sites.checkout.after', [
        'order_id' => $orderId,
        'phone' => $phone,
        'lines' => $orderLines,
        'booking' => true,
        'operator_id' => $operatorId,
        'dt_start' => $dtStart,
    ]);

    $masterCard = SitesBooking::operatorPublicCard($operatorId);
    SitesCart::clear();
    SitesCart::setBookingConfirm([
        'id' => $orderId,
        'when' => date('d.m.Y H:i', $dtStart),
        'date' => date('d.m.Y', $dtStart),
        'time' => date('H:i', $dtStart),
        'master' => (string)($masterCard['display_name'] ?? ''),
        'comment' => $comment,
        'phone' => $phone,
    ]);
    flash_set('ok', t('sites.cart.placed_booking', [
        'id' => $orderId,
        'when' => date('d.m.Y H:i', $dtStart),
    ]));
    redirect('/cart');
}

$sum = 0.0;
foreach ($orderLines as $line) {
    $sum += (float)($line['price'] ?? 0);
}

$idUser = SitesGuest::clientId();
if ($idUser < 1 && class_exists(ClientsRepository::class, false) && Database::tableExists('base_clients')) {
    $client = ClientsRepository::findByPhoneDigits($phone);
    if ($client) {
        $idUser = (int)$client['id'];
    }
}

$orderId = OrdersRepository::create([
    'phone' => $phone,
    'mail' => $mail,
    'comment' => $comment,
    'cart_lines' => $orderLines,
    'summ' => $sum,
    'summ_real' => $sum,
    'status' => 'cashComplete',
    'id_user' => $idUser,
    'dt_event' => time(),
    'source' => 'site',
]);

EventBus::emit('sites.checkout.after', [
    'order_id' => $orderId,
    'phone' => $phone,
    'lines' => $orderLines,
    'booking' => false,
]);

SitesCart::clear();
flash_set('ok', t('sites.cart.placed') . ' — ' . t('sites.cart.order_id', ['id' => $orderId]));
redirect('/cart');
