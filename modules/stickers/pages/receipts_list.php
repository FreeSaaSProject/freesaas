<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

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
$actIds = [];
foreach ($result['items'] as $it) {
    $actIds[] = (int)$it['id'];
}
$dpsByAct = Acts2InDps::movesByActIds($actIds);
$ledgerOn = Acts2InDps::ledgerReady();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'dps_by_act' => $dpsByAct,
        'ledger_enabled' => $ledgerOn,
        'filters' => [
            'q' => $q,
            'id_stock' => $idStock > 0 ? $idStock : null,
        ],
        'stocks' => $stocks,
        'suppliers' => $suppliers,
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
    ]);
    return;
}

View::render('receipts_list', [
    'title' => t('stickers.menu.receipts'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'idStock' => $idStock,
    'stocks' => $stocks,
    'suppliers' => $suppliers,
    'dpsByAct' => $dpsByAct,
    'ledgerOn' => $ledgerOn,
    'canEdit' => Auth::can('stickers.edit'),
    'canDps' => $ledgerOn && Auth::can('ledger.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'stickers');
