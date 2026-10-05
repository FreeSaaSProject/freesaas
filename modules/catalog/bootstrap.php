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

EventBus::on('core.user_edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    $products = [];
    $listed = ProductsRepository::list([
        'enabled' => '1',
        'is_material' => '0',
        'limit' => 2000,
        'order' => 'p.category ASC, p.name ASC',
    ]);
    foreach ($listed['items'] as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $products[$id] = (string)($r['name'] ?? ('#' . $id));
    }
    $rpSet = [];
    foreach (UsersRepository::parseHashIds((string)($user['RESTRICT_PROCS'] ?? '')) as $pid) {
        $rpSet[(int)$pid] = true;
    }
    $withHeading = true;
    ob_start();
    require __DIR__ . '/views/_user_edit_restrict.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('core.user_edit.save', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    $data['RESTRICT_PROCS'] = UsersRepository::encodeHashIds(array_map('intval', (array)($post['RESTRICT_PROCS'] ?? [])));
    $payload['data'] = $data;
    return $payload;
});
