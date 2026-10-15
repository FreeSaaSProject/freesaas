<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/RatesRepository.php';

$pages = [
    '/rates' => ['permission' => 'rates.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/rates/new' => ['permission' => 'rates.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/rates/{id}/edit' => ['permission' => 'rates.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'rates',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/rates/pages/' . $file);
        },
    ]);
}

BulkActions::register('rates', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'rates.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RatesRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('rates', 'copy', [
    'label' => 'rates.bulk.copy',
    'permission' => 'rates.edit',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RatesRepository::copyByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('rates', 'zerofill', [
    'label' => 'rates.bulk.zerofill',
    'permission' => 'rates.edit',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RatesRepository::zerofillByIds($ids), 'skipped' => []];
    },
]);
