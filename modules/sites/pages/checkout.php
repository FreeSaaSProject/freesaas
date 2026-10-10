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

$phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? '')) ?? '';
if (strlen($phone) === 11 && $phone[0] === '8') {
    $phone = '7' . substr($phone, 1);
}
if (strlen($phone) < 10) {
    flash_set('error', t('sites.cart.err_phone'));
    redirect('/cart');
}

$mail = trim((string)($_POST['mail'] ?? ''));
$comment = trim((string)($_POST['comment'] ?? ''));
$operatorId = (int)($_POST['operator_id'] ?? 0);
$dtStart = (int)($_POST['dt_start'] ?? 0);
$freeTicket = !empty($_POST['free_ticket']) ? 1 : 0;
$needsBooking = SitesBooking::needsBooking();

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
$phone = (string)($payload['phone'] ?? $phone);
$mail = (string)($payload['mail'] ?? $mail);
$comment = (string)($payload['comment'] ?? $comment);
$operatorId = (int)($payload['operator_id'] ?? $operatorId);
$dtStart = (int)($payload['dt_start'] ?? $dtStart);
$freeTicket = !empty($payload['free_ticket']) ? 1 : 0;

if ($needsBooking) {
    if ($operatorId < 1 || $dtStart < 1) {
        flash_set('error', t('sites.cart.err_slot'));
        redirect('/cart');
    }
    try {
        $booked = BookingBook::createOrder([
            'phone' => $phone,
            'mail' => $mail,
            'comment' => $comment,
            'product_ids' => SitesBooking::productIdsFlat(),
            'cart_lines' => $orderLines,
            'operator_id' => $operatorId,
            'dt_start' => $dtStart,
            'free_ticket' => $freeTicket,
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

    SitesCart::clear();
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

$idUser = 0;
if (class_exists('ClientsRepository', false) && Database::tableExists('base_clients')) {
    $client = ClientsRepository::findByTel($phone);
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
