<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/OrdersRepository.php';
require_once __DIR__ . '/lib/OrdersConfig.php';
require_once __DIR__ . '/lib/OrdersMaxNotify.php';

Assets::register('orders', [
    'css' => ['assets/css/orders.css'],
]);

$pages = [
    '/orders' => ['permission' => 'orders.view', 'methods' => ['GET'], 'file' => 'list.php'],
    '/orders/settings' => [
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
    ],
    '/orders/{id}' => ['permission' => 'orders.view', 'methods' => ['GET'], 'file' => 'view.php'],
    '/orders/{id}/edit' => ['permission' => 'orders.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'orders',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/orders/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $route['capability'] = $def['capability'];
    }
    if (!empty($def['permission'])) {
        $route['permission'] = $def['permission'];
    }
    ModuleManager::registerRoute($path, $route);
}

BulkActions::register('orders', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'orders.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return OrdersRepository::deleteByIds($ids);
    },
]);

EventBus::on('core.bulk.check_item', static function (array $payload): array {
    if (($payload['list_id'] ?? '') !== 'orders' || ($payload['action'] ?? '') !== 'delete') {
        return $payload;
    }
    $id = (int)($payload['id'] ?? 0);
    $row = $id > 0 ? OrdersRepository::find($id) : null;
    if ($row === null) {
        $payload['blocked'] = true;
        $payload['reason'] = t('core.entity.err.not_found');
        return $payload;
    }
    $eventId = (int)($row['id_event_use'] ?? 0) + (int)($row['id_event_paid'] ?? 0);
    if ($eventId > 0) {
        $payload['blocked'] = true;
        $payload['reason'] = t('orders.err.delete_has_event');
        return $payload;
    }
    if (!empty($row['epaid']) || (float)($row['summ_real'] ?? 0) > 0) {
        $payload['blocked'] = true;
        $payload['reason'] = t('orders.err.delete_has_payment');
    }
    return $payload;
});

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

EventBus::on('calendar.settings.sections', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $payload['html'] = (string)($payload['html'] ?? '') . View::capture('_settings_section', [
        'cfg' => OrdersConfig::formValues(),
        'bots_enabled' => ModuleManager::isEnabled('bots'),
    ], 'orders');
    return $payload;
});

EventBus::on('calendar.settings.save', static function ($payload) {
    if (!is_array($payload) || !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    if (!isset($post['orders_settings_save'])) {
        return $payload;
    }
    OrdersConfig::saveFromPost($post);
    return $payload;
});

EventBus::on('calendar.day.stats', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (!Auth::can('orders.view') || !Database::tableExists('base_orders')) {
        return $payload;
    }
    // Same gate as notice chips: administrator reference info.
    if (!Auth::capability('SHOW_ADMINISTRATOR_DATA') && !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    $days = is_array($payload['days'] ?? null) ? $payload['days'] : [];
    foreach ($days as $date => $day) {
        if (!is_array($day) || !empty($day['is_future'])) {
            continue;
        }
        $tsStart = (int)($day['ts_start'] ?? 0);
        $tsEnd = (int)($day['ts_end'] ?? 0);
        if ($tsStart <= 0) {
            continue;
        }
        $staffFilter = isset($day['staff_filter']) ? (int)$day['staff_filter'] : null;
        if ($staffFilter !== null && $staffFilter < 1) {
            $staffFilter = null;
        }
        $n = count(OrdersRepository::listForDayRange($tsStart, $tsEnd, $staffFilter));
        if ($n < 1) {
            continue;
        }
        $sections = is_array($day['sections'] ?? null) ? $day['sections'] : [];
        $sections[] = [
            'id' => 'orders.day_chip',
            'module' => 'orders',
            'order' => 40,
            'html' => View::capture('_day_stats_chip', [
                'count' => $n,
                'date' => (string)$date,
            ], 'orders'),
            'data' => ['count' => $n],
        ];
        $day['sections'] = $sections;
        $payload['days'][$date] = $day;
    }
    return $payload;
});
