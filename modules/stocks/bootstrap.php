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
