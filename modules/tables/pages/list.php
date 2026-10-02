<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '');
$lq = ListQuery::resolve(['list_id' => 'tables', 'allowed' => TablesRepository::listSortMap(), 'default' => 'name', 'default_dir' => 'asc', 'per_page' => 50]);
$filters = array_merge(['q' => $q, 'enabled' => $enabled], $lq['query']);
BulkActions::tryHandle('tables', '/tables', $filters);
$result = TablesRepository::list([
    'q' => $q,
    'enabled' => $enabled === '' ? null : $enabled,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);
View::render('list', [
    'title' => t('tables.menu.list'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'enabled' => $enabled,
    'canEdit' => Auth::can('tables.edit'),
    'sort' => $lq['sort'], 'dir' => $lq['dir'], 'page' => $lq['page'], 'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'], 'filters' => $filters, 'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'tables');
