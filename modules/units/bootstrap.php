<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/UnitsRepository.php';

$pages = [
    '/units' => ['permission' => 'units.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/units/new' => ['permission' => 'units.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/units/{id}/edit' => ['permission' => 'units.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'units',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/units/pages/' . $file);
        },
    ]);
}

BulkActions::register('units', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'units.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => UnitsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
