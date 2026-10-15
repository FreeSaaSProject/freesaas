<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/SuppliersRepository.php';

$pages = [
    '/suppliers' => ['permission' => 'suppliers.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/suppliers/new' => ['permission' => 'suppliers.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/suppliers/{id}/edit' => ['permission' => 'suppliers.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'suppliers',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/suppliers/pages/' . $file);
        },
    ]);
}

BulkActions::register('suppliers', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'suppliers.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => SuppliersRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
