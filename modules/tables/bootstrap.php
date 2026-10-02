<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/TablesRepository.php';

$pages = [
    '/tables' => ['permission' => 'tables.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/tables/new' => ['permission' => 'tables.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/tables/{id}/edit' => ['permission' => 'tables.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
];
foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'tables',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/tables/pages/' . $file);
        },
    ]);
}

BulkActions::register('tables', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'tables.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => TablesRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
