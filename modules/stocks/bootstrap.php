<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/StocksRepository.php';
require_once __DIR__ . '/lib/StockLeftovers.php';

$pages = [
    '/stocks' => ['permission' => 'stocks.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/stocks/leftovers' => ['permission' => 'stocks.view', 'methods' => ['GET'], 'file' => 'leftovers.php'],
    '/stocks/new' => ['permission' => 'stocks.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/stocks/{id}/edit' => ['permission' => 'stocks.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'stocks',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/stocks/pages/' . $file);
        },
    ]);
}

BulkActions::register('stocks', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'stocks.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => StocksRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

StatusesRepository::registerFieldGroup([
    'title' => 'stocks.statuses.group',
    'when' => 'stocks',
    'fields' => [
        ['key' => 'STOCKS_DATA', 'label' => 'stocks.statuses.field.STOCKS_DATA', 'type' => 'checkbox'],
        ['key' => 'GOOD_USE', 'label' => 'stocks.statuses.field.GOOD_USE', 'type' => 'checkbox'],
        ['key' => 'STICKER_ADV_ACC', 'label' => 'stocks.statuses.field.STICKER_ADV_ACC', 'type' => 'checkbox'],
    ],
]);

EventBus::on('core.user_edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    ob_start();
    require __DIR__ . '/views/_user_edit_access.php';
    $payload['html_access'] = ($payload['html_access'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('core.user_edit.save', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    $data['STOCK_REVISION'] = !empty($post['STOCK_REVISION']) ? 1 : 0;
    $payload['data'] = $data;
    return $payload;
});

EventBus::on('core.user_edit.profile', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (empty($payload['can_see_sensitive'])) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    ob_start();
    require __DIR__ . '/views/_user_profile_flags.php';
    $payload['html_flags'] = ($payload['html_flags'] ?? '') . (string)ob_get_clean();
    return $payload;
});
