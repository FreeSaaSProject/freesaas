<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CabinetsRepository.php';

$pages = [
    '/cabinets' => ['permission' => 'cabinets.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/cabinets/new' => ['permission' => 'cabinets.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/cabinets/{id}/edit' => ['permission' => 'cabinets.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'cabinets',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/cabinets/pages/' . $file);
        },
    ]);
}

BulkActions::register('cabinets', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'cabinets.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => CabinetsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
