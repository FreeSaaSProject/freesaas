<?php
declare(strict_types=1);

$opts = [
    'order_id' => (int)($_GET['order_id'] ?? 0),
    'status' => (string)($_GET['status'] ?? ''),
    'provider' => (string)($_GET['provider'] ?? ''),
    'limit' => 100,
    'offset' => 0,
];
$list = AcquiringPaymentsRepository::list($opts);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'items' => $list['items'], 'total' => $list['total']]);
    return;
}

View::render('payments', [
    'title' => t('acquiring.payments.title'),
    'items' => $list['items'],
    'breadcrumbs' => Breadcrumbs::withHome([['label' => t('acquiring.payments.title'), 'current' => true]]),
], 'layout', 'acquiring');
