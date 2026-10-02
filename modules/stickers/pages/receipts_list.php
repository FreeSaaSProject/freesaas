<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$idStock = (int)($_GET['id_stock'] ?? 0);
$lq = ListQuery::resolve([
    'list_id' => 'stickers_receipts',
    'allowed' => Acts2InRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);
$filters = array_merge(['q' => $q, 'id_stock' => $idStock ?: ''], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}
BulkActions::tryHandle('stickers_receipts', '/stickers/receipts', $filters);
$result = Acts2InRepository::list([
    'q' => $q,
    'id_stock' => $idStock > 0 ? $idStock : null,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);
$stocks = class_exists('StocksRepository', false) ? StocksRepository::names() : [];
$suppliers = class_exists('SuppliersRepository', false) ? SuppliersRepository::names(false) : [];

View::render('receipts_list', [
    'title' => t('stickers.menu.receipts'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'idStock' => $idStock,
    'stocks' => $stocks,
    'suppliers' => $suppliers,
    'canEdit' => Auth::can('stickers.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'stickers');
