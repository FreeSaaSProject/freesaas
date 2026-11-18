<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ResourcesRepository.php';
require_once __DIR__ . '/lib/ProductResourcesRepository.php';
require_once __DIR__ . '/lib/ResourceAllocationsRepository.php';
require_once __DIR__ . '/lib/ResourceBooking.php';

$pages = [
    '/resources' => ['permission' => 'resources.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/resources/new' => ['permission' => 'resources.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/resources/{id}/edit' => ['permission' => 'resources.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'resources',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/resources/pages/' . $file);
        },
    ]);
}

BulkActions::register('resources', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'resources.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => ResourcesRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

EventBus::on('core.bulk.check_item', static function (array $payload): array {
    if (($payload['list_id'] ?? '') !== 'resources' || ($payload['action'] ?? '') !== 'delete') {
        return $payload;
    }
    $id = (int)($payload['id'] ?? 0);
    if ($id < 1 || !ResourceAllocationsRepository::tableReady()) {
        return $payload;
    }
    $future = ResourceAllocationsRepository::countActiveForResource($id, time());
    if ($future > 0) {
        $payload['block'] = t('resources.bulk.has_future');
    }
    return $payload;
});

EventBus::on('catalog.product_edit.sections', static function ($payload) {
    if (!is_array($payload) || !ResourceBooking::ready()) {
        return $payload;
    }
    $productId = (int)($payload['product_id'] ?? 0);
    $row = is_array($payload['row'] ?? null) ? $payload['row'] : [];
    $all = ResourcesRepository::list(['enabled' => 1, 'limit' => 500, 'order' => 'NAME ASC']);
    $linked = [];
    foreach (ProductResourcesRepository::forProduct($productId) as $link) {
        $linked[(int)$link['resource_id']] = max(1, (int)$link['qty_needed']);
    }
    // Keep posted values on validation error.
    $postIds = $payload['post']['resource_ids'] ?? null;
    if (is_array($postIds)) {
        $linked = [];
        $qtyMap = is_array($payload['post']['resource_qty'] ?? null) ? $payload['post']['resource_qty'] : [];
        foreach ($postIds as $rid) {
            $rid = (int)$rid;
            if ($rid > 0) {
                $linked[$rid] = max(1, (int)($qtyMap[$rid] ?? $qtyMap[(string)$rid] ?? 1));
            }
        }
    }
    $resources = $all['items'];
    $selected = $linked;
    ob_start();
    require __DIR__ . '/views/_product_edit_resources.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('catalog.product_edit.save', static function ($payload) {
    if (!is_array($payload) || !ResourceBooking::ready()) {
        return $payload;
    }
    $id = (int)($payload['product_id'] ?? 0);
    if ($id < 1) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    // Section always posts resource_ids when resources module is on (may be empty array).
    if (!array_key_exists('resource_ids', $post) && !array_key_exists('resources_section', $post)) {
        return $payload;
    }
    ProductResourcesRepository::saveFromPost($id, $post);
    return $payload;
});

EventBus::on('booking.book.after', static function ($payload) {
    if (!is_array($payload) || !ResourceBooking::ready()) {
        return $payload;
    }
    $orderId = (int)($payload['order_id'] ?? 0);
    $row = is_array($payload['row'] ?? null) ? $payload['row'] : null;
    if ($row === null && $orderId > 0 && class_exists(OrdersRepository::class, false)) {
        $row = OrdersRepository::find($orderId);
    }
    if (is_array($row)) {
        if (!isset($row['id']) && $orderId > 0) {
            $row['id'] = $orderId;
        }
        ResourceBooking::syncOrder($row);
    }
    return $payload;
});

EventBus::on('orders.cancelled', static function ($payload) {
    if (!is_array($payload) || !ResourceBooking::ready()) {
        return $payload;
    }
    $orderId = (int)($payload['order_id'] ?? $payload['id'] ?? 0);
    if ($orderId < 1 && is_array($payload['order'] ?? null)) {
        $orderId = (int)($payload['order']['id'] ?? 0);
    }
    if ($orderId > 0) {
        ResourceBooking::release(ResourceAllocationsRepository::SOURCE_ORDER, $orderId);
    }
    return $payload;
});

EventBus::on('orders.completed', static function ($payload) {
    if (!is_array($payload) || !ResourceBooking::ready()) {
        return $payload;
    }
    $orderId = (int)($payload['order_id'] ?? $payload['id'] ?? 0);
    if ($orderId < 1 && is_array($payload['order'] ?? null)) {
        $orderId = (int)($payload['order']['id'] ?? 0);
    }
    if ($orderId > 0) {
        ResourceBooking::release(ResourceAllocationsRepository::SOURCE_ORDER, $orderId);
    }
    return $payload;
});
