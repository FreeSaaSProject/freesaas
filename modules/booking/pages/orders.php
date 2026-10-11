<?php
declare(strict_types=1);

$clientId = (int)($_GET['client_id'] ?? $_GET['id_user'] ?? 0);
$limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));

if ($clientId < 1) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error('client_id required', 400);
    }
    flash('err', t('booking.err.client'));
    redirect('/booking/new');
}

if (!class_exists(OrdersRepository::class, false)) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error(t('booking.err.orders'), 503);
    }
    flash('err', t('booking.err.orders'));
    redirect('/booking/new');
}

$items = OrdersRepository::listFutureByClient($clientId, $limit);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'client_id' => $clientId, 'orders' => $items]);
}

View::render('orders', [
    'title' => t('booking.orders.title'),
    'client_id' => $clientId,
    'orders' => $items,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('booking.menu.new'), 'href' => '/booking/new'],
        ['label' => t('booking.orders.title'), 'current' => true],
    ]),
], 'layout', 'booking');
