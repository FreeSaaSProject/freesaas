<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$idStock = (int)($_GET['id_stock'] ?? 0);
$lq = ListQuery::resolve([
    'list_id' => 'stickers_writeoffs',
    'allowed' => Acts2OutRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);
$filters = array_merge(['q' => $q, 'id_stock' => $idStock ?: ''], $lq['query']);
$result = Acts2OutRepository::list([
    'q' => $q,
    'id_stock' => $idStock > 0 ? $idStock : null,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);
$stocks = class_exists('StocksRepository', false) ? StocksRepository::names() : [];

View::render('writeoffs_list', [
    'title' => t('stickers.menu.writeoffs'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'idStock' => $idStock,
    'stocks' => $stocks,
    'canEdit' => Auth::can('stickers.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
], 'layout', 'stickers');
