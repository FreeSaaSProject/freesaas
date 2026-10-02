<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$idAct = (int)($_GET['id_act'] ?? 0);
$idGood = (int)($_GET['id_good'] ?? 0);
$lq = ListQuery::resolve([
    'list_id' => 'stickers_ins',
    'allowed' => StickerInsRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);
$filters = array_merge([
    'q' => $q,
    'id_act' => $idAct ?: '',
    'id_good' => $idGood ?: '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}
BulkActions::tryHandle('stickers_ins', '/stickers/ins', $filters);
$result = StickerInsRepository::list([
    'q' => $q,
    'id_act' => $idAct > 0 ? $idAct : null,
    'id_good' => $idGood > 0 ? $idGood : null,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);
$products = class_exists('ProductsRepository', false) ? ProductsRepository::names(false) : [];
$stocks = class_exists('StocksRepository', false) ? StocksRepository::names() : [];

View::render('ins_list', [
    'title' => t('stickers.menu.ins'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'idAct' => $idAct,
    'idGood' => $idGood,
    'products' => $products,
    'stocks' => $stocks,
    'canEdit' => Auth::can('stickers.edit'),
    'canWriteoff' => Auth::can('stickers.writeoff'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'stickers');
