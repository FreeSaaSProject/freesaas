<?php
declare(strict_types=1);

$body = $_POST;
if (AgentHttp::wantsJson()) {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = array_merge($body, $decoded);
        }
    }
}

$orderId = (int)($body['order_id'] ?? $body['id'] ?? $_GET['order_id'] ?? 0);
$clientId = (int)($body['client_id'] ?? $body['id_user'] ?? $_GET['client_id'] ?? 0);

if (!class_exists(OrdersRepository::class, false)) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error(t('booking.err.orders'), 503);
    }
    flash('err', t('booking.err.orders'));
    redirect('/booking/new');
}

$res = OrdersRepository::cancelForClient($orderId, $clientId);
if (empty($res['ok'])) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error((string)($res['error'] ?? 'cancel failed'), 400);
    }
    flash('err', (string)($res['error'] ?? t('booking.err.cancel')));
    redirect('/booking/orders?client_id=' . $clientId);
}

if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'cancelled' => 1, 'order_id' => $orderId]);
}

flash('ok', t('booking.flash.cancelled'));
redirect('/booking/orders?client_id=' . $clientId);
