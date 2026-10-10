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

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = OrdersRepository::dataFromPost($_POST);
        OrdersRepository::update($id, $data);
        $order = OrdersRepository::find($id) ?: $order;
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'id' => $id,
                'order' => $order,
                'cart' => OrdersRepository::decodeCart((string)($order['cart'] ?? '')),
            ]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $order = array_merge($order, [
            'status' => (string)($_POST['status'] ?? $order['status'] ?? '0'),
            'id_cosmetolog' => (int)($_POST['id_cosmetolog'] ?? 0),
            'id_user' => (int)($_POST['id_user'] ?? 0),
            'phone' => (string)($_POST['phone'] ?? ''),
            'comment' => (string)($_POST['comment'] ?? ''),
            'mail' => (string)($_POST['mail'] ?? ''),
            'free_ticket' => !empty($_POST['free_ticket']) ? 1 : 0,
            'summ' => (float)str_replace(',', '.', (string)($_POST['summ'] ?? '0')),
            'summ_real' => (float)str_replace(',', '.', (string)($_POST['summ_real'] ?? '0')),
            'id_promocode' => (int)($_POST['id_promocode'] ?? 0),
            'text_promocode' => (string)($_POST['text_promocode'] ?? ''),
            'dt_event' => OrdersRepository::parseDtEvent(
                (string)($_POST['dt_event'] ?? ''),
                '',
                ''
            ),
            'cart' => OrdersRepository::encodeCart(OrdersRepository::cartLinesFromPost($_POST)),
        ]);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$statusChoices = [];
foreach (OrdersRepository::editableStatuses() as $code) {
    $statusChoices[$code] = OrdersRepository::statusLabel($code);
}
$currentStatus = (string)($order['status'] ?? '0');
if ($currentStatus === '') {
    $currentStatus = '0';
}
if (!isset($statusChoices[$currentStatus])) {
    $statusChoices[$currentStatus] = OrdersRepository::statusLabel($currentStatus);
}

$cart = OrdersRepository::decodeCart((string)($order['cart'] ?? ''));
$dtEvent = (int)($order['dt_event'] ?? 0);
$dayStart = $dtEvent > 0
    ? (int)mktime(0, 0, 0, (int)date('n', $dtEvent), (int)date('j', $dtEvent), (int)date('Y', $dtEvent))
    : (int)mktime(0, 0, 0);
$staffId = (int)($order['id_cosmetolog'] ?? 0);

$staffOptions = OrdersRepository::staffOptionsForDay($dayStart, $staffId);

$keepProductIds = [];
foreach ($cart as $line) {
    $pid = (int)($line['id'] ?? 0);
    if ($pid > 0) {
        $keepProductIds[] = $pid;
    }
}

$productOptions = [];
if (class_exists(BookingCatalog::class, false) && $staffId > 0) {
    foreach (BookingCatalog::listServicesForOperator($staffId, $dayStart, $keepProductIds, 500) as $p) {
        $price = (float)($p['price3'] ?? $p['price'] ?? 0);
        if ($price <= 0) {
            $price = (float)($p['price'] ?? 0);
        }
        $productOptions[] = [
            'id' => (int)($p['id'] ?? 0),
            'name' => (string)($p['name'] ?? ''),
            'price' => $price,
        ];
    }
} elseif (class_exists(BookingCatalog::class, false)) {
    foreach (BookingCatalog::listServices(500) as $p) {
        $price = (float)($p['price3'] ?? $p['price'] ?? 0);
        if ($price <= 0) {
            $price = (float)($p['price'] ?? 0);
        }
        $productOptions[] = [
            'id' => (int)($p['id'] ?? 0),
            'name' => (string)($p['name'] ?? ''),
            'price' => $price,
        ];
    }
} elseif (class_exists(ProductsRepository::class, false)) {
    $list = ProductsRepository::list([
        'enabled' => '1',
        'is_material' => '0',
        'limit' => 400,
        'order' => 'p.name ASC',
    ]);
    foreach ($list['items'] as $p) {
        $price = (float)($p['price3'] ?? 0);
        if ($price <= 0) {
            $price = (float)($p['price'] ?? 0);
        }
        $productOptions[] = [
            'id' => (int)($p['id'] ?? 0),
            'name' => (string)($p['name'] ?? ''),
            'price' => $price,
        ];
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'id' => $id,
        'order' => $order,
        'cart' => $cart,
        'staff_options' => $staffOptions,
        'product_options' => $productOptions,
        'status_choices' => $statusChoices,
    ]);
    return;
}

View::render('edit', [
    'title' => t('orders.edit.title', ['id' => $id]),
    'order' => $order,
    'cart' => $cart,
    'productOptions' => $productOptions,
    'message' => $message,
    'error' => $error,
    'staffOptions' => $staffOptions,
    'statusChoices' => $statusChoices,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('calendar.menu.group'), 'href' => '/calendar'],
        ['label' => t('orders.menu.list'), 'href' => '/orders'],
        ['label' => '#' . $id, 'href' => '/orders/' . $id],
        ['label' => t('core.common.edit'), 'current' => true],
    ]),
], 'layout', 'orders');
