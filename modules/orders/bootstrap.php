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

EventBus::on('core.dashboard.catalog', static function ($catalog) {
    if (!is_array($catalog)) {
        $catalog = [];
    }
    if (!Auth::can('orders.view') || !Database::tableExists('base_orders')) {
        return $catalog;
    }
    $catalog[] = [
        'id' => 'orders.quick',
        'title' => t('orders.widget.quick.title'),
        'module' => 'orders',
        'default_order' => 12,
        'default_on' => true,
        'settings' => [],
    ];
    return $catalog;
});

EventBus::on('core.dashboard.widgets', static function ($widgets) {
    if (!is_array($widgets)) {
        $widgets = [];
    }
    if (!Auth::can('orders.view') || !Database::tableExists('base_orders')) {
        return $widgets;
    }
    if (!DashboardWidgets::isEnabled('orders.quick')) {
        return $widgets;
    }

    $todayN = (int)(OrdersRepository::list(['status' => 'newToday', 'limit' => 1])['total'] ?? 0);
    $futureN = (int)(OrdersRepository::list(['status' => 'new', 'limit' => 1])['total'] ?? 0);

    $html = View::capture('_widget_quick', [
        'todayCount' => $todayN,
        'futureCount' => $futureN,
    ], 'orders');

    $widgets[] = [
        'id' => 'orders.quick',
        'title' => t('orders.widget.quick.title'),
        'html' => $html,
        'tone' => ($todayN + $futureN) > 0 ? 'warn' : 'neutral',
        'size' => 'sm',
    ];
    return $widgets;
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
