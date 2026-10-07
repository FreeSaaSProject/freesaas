<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$order = OrdersRepository::find($id);
if (!$order) {
    if ($wantsJson) {
        AgentHttp::error(t('orders.err.not_found', ['id' => $id]), 404);
        return;
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => t('orders.err.not_found', ['id' => $id]),
    ]);
    return;
}

$cart = OrdersRepository::enrichCartNames(
    OrdersRepository::decodeCart(isset($order['cart']) ? (string)$order['cart'] : null)
);

$procedure = null;
$procedureMissing = false;
$eventUseId = (int)($order['id_event_use'] ?? 0);
if ($eventUseId > 0 && Database::tableExists('base_procedures')) {
    $st = Database::pdo()->prepare(
        'SELECT ID AS id, DTSTART AS dtstart, ID_USER AS id_user, ENABLED AS enabled
         FROM base_procedures WHERE ID = ? LIMIT 1'
    );
    $st->execute([$eventUseId]);
    $procedure = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$procedure) {
        $procedureMissing = true;
    }
} elseif ($eventUseId > 0) {
    $procedureMissing = true;
}

$listUrl = '/orders';
$ret = (string)($_GET['ret'] ?? '');
if ($ret !== '' && strpos($ret, '/orders') === 0) {
    $listUrl = $ret;
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'id' => $id,
        'order' => $order,
        'cart' => $cart,
        'procedure' => $procedure,
        'procedure_missing' => $procedureMissing,
    ]);
    return;
}

View::render('view', [
    'title' => t('orders.view.title', ['id' => $id]),
    'order' => $order,
    'cart' => $cart,
    'procedure' => $procedure,
    'procedureMissing' => $procedureMissing,
    'canEdit' => Auth::can('orders.edit'),
    'listUrl' => $listUrl,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('orders.menu.list'), 'href' => $listUrl],
        ['label' => '#' . $id, 'current' => true],
    ]),
], 'layout', 'orders');
