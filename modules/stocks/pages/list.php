<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$q = trim((string)($_GET['q'] ?? ''));
$type = (string)($_GET['type'] ?? '');
if (!in_array($type, ['', '1', '2'], true)) {
    $type = '';
}

$lq = ListQuery::resolve([
    'list_id' => 'stocks',
    'allowed' => StocksRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'asc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'type' => $type,
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('stocks', '/stocks', $filters);

$result = StocksRepository::list([
    'q' => $q,
    'type' => $type === '' ? null : $type,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$cabinets = [];
if (class_exists('CabinetsRepository', false)) {
    foreach (CabinetsRepository::list(['limit' => 0])['items'] as $c) {
        $cabinets[(int)$c['id']] = (string)$c['name'];
    }
}
$stockNames = StocksRepository::names();

$bulkFlash = BulkActions::consumeFlash();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => [
            'q' => $q,
            'type' => $type === '' ? null : $type,
        ],
        'cabinets' => $cabinets,
        'stock_names' => $stockNames,
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
    ]);
    return;
}

View::render('list', [
    'title' => t('stocks.menu.list'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'type' => $type,
    'cabinets' => $cabinets,
    'stockNames' => $stockNames,
    'canEdit' => Auth::can('stocks.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
], 'layout', 'stocks');
