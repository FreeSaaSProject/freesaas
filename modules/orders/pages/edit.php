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


if (EntityDelete::tryHandle('orders', $id, '/orders', [
    'stay_path' => '/orders/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'set_client_status' || $action === 'block_online') {
            if (!Auth::can('clients.edit')) {
                throw new RuntimeException(t('orders.edit.err_clients_perm'));
            }
            if (!class_exists(ClientsRepository::class, false) || !class_exists(ClientStatusesRepository::class, false)) {
                throw new RuntimeException(t('orders.edit.err_no_client'));
            }
            $clientId = (int)($order['id_user'] ?? 0);
            $clientRow = null;
            if ($clientId > 0) {
                $clientRow = ClientsRepository::find($clientId);
            }
            if ($clientRow === null) {
                $phoneDigits = preg_replace('/\D+/', '', (string)($order['phone'] ?? '')) ?? '';
                $clientRow = $phoneDigits !== '' ? ClientsRepository::findByPhoneDigits($phoneDigits) : null;
            }
            if ($clientRow === null) {
                throw new RuntimeException(t('orders.edit.err_no_client'));
            }
            $clientId = (int)$clientRow['id'];
            if ((int)($order['id_user'] ?? 0) < 1) {
                Database::pdo()->prepare(
                    'UPDATE base_orders SET ID_USER = ? WHERE ID = ? AND ID_USER = 0'
                )->execute([$clientId, $id]);
                $order['id_user'] = $clientId;
            }
            if ($action === 'block_online') {
                $statusId = ClientStatusesRepository::firstBlockOnlineStatusId();
                if ($statusId < 1) {
                    throw new RuntimeException(t('orders.edit.err_no_block_status'));
                }
            } else {
                $statusId = max(0, (int)($_POST['client_status_id'] ?? 0));
            }
            ClientsRepository::setStatusId($clientId, $statusId);
            $order = OrdersRepository::find($id) ?: $order;
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $id,
                    'client_id' => $clientId,
                    'client_status_id' => $statusId,
                    'order' => $order,
                ]);
                return;
            }
            $message = $action === 'block_online'
                ? t('orders.edit.blocked_online')
                : t('orders.edit.client_status_saved');
        } else {
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
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        if ($action === '' || $action === 'save') {
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

$clientCard = null;
$clientStatusId = 0;
$clientStatusChoices = [];
$canEditClientStatus = Auth::can('clients.edit')
    && class_exists(ClientsRepository::class, false)
    && class_exists(ClientStatusesRepository::class, false);
$hasBlockOnlineStatus = false;
if ($canEditClientStatus) {
    $cid = (int)($order['id_user'] ?? 0);
    if ($cid > 0) {
        $clientCard = ClientsRepository::find($cid);
    }
    if ($clientCard === null) {
        $phoneDigits = preg_replace('/\D+/', '', (string)($order['phone'] ?? '')) ?? '';
        if ($phoneDigits !== '') {
            $clientCard = ClientsRepository::findByPhoneDigits($phoneDigits);
        }
    }
    if ($clientCard !== null) {
        $clientStatusId = (int)($clientCard['status_id'] ?? 0);
        $clientStatusChoices = ClientStatusesRepository::names(true);
        $hasBlockOnlineStatus = ClientStatusesRepository::firstBlockOnlineStatusId() > 0;
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
        'client' => $clientCard !== null && class_exists(ClientsRepository::class, false)
            ? ClientsRepository::forAgent($clientCard)
            : null,
        'client_status_id' => $clientStatusId,
        'client_status_choices' => $clientStatusChoices,
        'can_edit_client_status' => $canEditClientStatus && $clientCard !== null,
        'has_block_online_status' => $hasBlockOnlineStatus,
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
    'clientCard' => $clientCard,
    'clientStatusId' => $clientStatusId,
    'clientStatusChoices' => $clientStatusChoices,
    'canEditClientStatus' => $canEditClientStatus && $clientCard !== null,
    'hasBlockOnlineStatus' => $hasBlockOnlineStatus,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('calendar.menu.group'), 'href' => '/calendar'],
        ['label' => t('orders.menu.list'), 'href' => '/orders'],
        ['label' => '#' . $id, 'href' => '/orders/' . $id],
        ['label' => t('core.common.edit'), 'current' => true],
    ]),
], 'layout', 'orders');
