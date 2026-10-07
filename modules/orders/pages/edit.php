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
            'comment' => (string)($_POST['comment'] ?? ''),
            'mail' => (string)($_POST['mail'] ?? ''),
            'free_ticket' => !empty($_POST['free_ticket']) ? 1 : 0,
        ]);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$staffOptions = [];
foreach (UsersRepository::listForCopy() as $u) {
    $label = trim((string)$u['name']);
    if ($label === '') {
        $label = (string)$u['login'];
    }
    $staffOptions[] = ['id' => (int)$u['id'], 'name' => $label];
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

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'id' => $id,
        'order' => $order,
        'staff_options' => $staffOptions,
        'status_choices' => $statusChoices,
    ]);
    return;
}

View::render('edit', [
    'title' => t('orders.edit.title', ['id' => $id]),
    'order' => $order,
    'message' => $message,
    'error' => $error,
    'staffOptions' => $staffOptions,
    'statusChoices' => $statusChoices,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('orders.menu.list'), 'href' => '/orders'],
        ['label' => '#' . $id, 'href' => '/orders/' . $id],
        ['label' => t('core.common.edit'), 'current' => true],
    ]),
], 'layout', 'orders');
