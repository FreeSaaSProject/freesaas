<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CategoriesRepository.php';
require_once __DIR__ . '/lib/ProductsRepository.php';
require_once __DIR__ . '/lib/ProductRelationsRepository.php';

$pages = [
    '/catalog' => ['permission' => 'catalog.view', 'methods' => ['GET', 'POST'], 'file' => 'browse.php'],
    '/catalog/{id}' => ['permission' => 'catalog.view', 'methods' => ['GET', 'POST'], 'file' => 'browse.php'],
    '/categories' => ['permission' => 'catalog.view', 'methods' => ['GET'], 'file' => 'categories_list.php'],
    '/categories/new' => ['permission' => 'catalog.edit', 'methods' => ['GET', 'POST'], 'file' => 'category_edit.php'],
    '/categories/{id}/edit' => ['permission' => 'catalog.edit', 'methods' => ['GET', 'POST'], 'file' => 'category_edit.php'],
    '/products' => ['permission' => 'catalog.view', 'methods' => ['GET'], 'file' => 'products_list.php'],
    '/products/new' => ['permission' => 'catalog.edit', 'methods' => ['GET', 'POST'], 'file' => 'product_edit.php'],
    '/products/{id}/edit' => ['permission' => 'catalog.edit', 'methods' => ['GET', 'POST'], 'file' => 'product_edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'catalog',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/catalog/pages/' . $file);
        },
    ]);
}

EventBus::on('core.bulk.check_item', static function (array $payload): array {
    if (!empty($payload['blocked'])) {
        return $payload;
    }
    $listId = (string)($payload['list_id'] ?? '');
    $action = (string)($payload['action'] ?? '');
    if ($action !== 'delete') {
        return $payload;
    }
    $id = (int)($payload['id'] ?? 0);
    if ($listId === 'units') {
        $n = ProductsRepository::countByUnitId($id);
        if ($n > 0) {
            $payload['blocked'] = true;
            $payload['reason'] = t('catalog.bulk.unit_in_use', ['n' => $n]);
        }
        return $payload;
    }
    if ($listId === 'suppliers') {
        $n = ProductsRepository::countBySupplierId($id);
        if ($n > 0) {
            $payload['blocked'] = true;
            $payload['reason'] = t('catalog.bulk.supplier_in_use', ['n' => $n]);
        }
        return $payload;
    }
    if ($listId === 'procedure_types') {
        $n = ProductsRepository::countByProcedureTypeId($id);
        if ($n > 0) {
            $payload['blocked'] = true;
            $payload['reason'] = t('catalog.bulk.type_in_use', ['n' => $n]);
        }
    }
    return $payload;
});
