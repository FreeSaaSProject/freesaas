<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/OrdersRepository.php';

$pages = [
    '/orders' => ['permission' => 'orders.view', 'methods' => ['GET'], 'file' => 'list.php'],
    '/orders/{id}' => ['permission' => 'orders.view', 'methods' => ['GET'], 'file' => 'view.php'],
    '/orders/{id}/edit' => ['permission' => 'orders.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'orders',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/orders/pages/' . $file);
        },
    ]);
}

EventBus::on('core.notices.collect', static function ($notices) {
    if (!is_array($notices)) {
        $notices = [];
    }
    if (!Auth::check()) {
        return $notices;
    }
    foreach (OrdersRepository::noticesForUser(Auth::userId()) as $n) {
        $notices[] = $n;
    }
    return $notices;
});

EventBus::on('core.bulk.check_item', static function (array $payload): array {
    if (!empty($payload['blocked'])) {
        return $payload;
    }
    $listId = (string)($payload['list_id'] ?? '');
    $action = (string)($payload['action'] ?? '');
    $id = (int)($payload['id'] ?? 0);
    if ($listId === 'clients' && $action === 'delete' && $id > 0) {
        $n = OrdersRepository::countByClientId($id);
        if ($n > 0) {
            $payload['blocked'] = true;
            $payload['reason'] = t('orders.bulk.client_in_use', ['n' => $n]);
        }
    }
    return $payload;
});
