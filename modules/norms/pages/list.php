<?php
declare(strict_types=1);

$idProc = (int)($_GET['id_proc'] ?? 0);
$idGood = (int)($_GET['id_good'] ?? 0);
$lq = ListQuery::resolve([
    'list_id' => 'norms',
    'allowed' => NormsRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);
$filters = array_merge(['id_proc' => $idProc ?: '', 'id_good' => $idGood ?: ''], $lq['query']);
BulkActions::tryHandle('norms', '/norms', $filters);
$result = NormsRepository::list([
    'id_proc' => $idProc > 0 ? $idProc : null,
    'id_good' => $idGood > 0 ? $idGood : null,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);
$products = class_exists('ProductsRepository', false) ? ProductsRepository::names(false) : [];

View::render('list', [
    'title' => t('norms.menu.list'),
    'items' => $result['items'],
    'total' => $result['total'],
    'idProc' => $idProc,
    'idGood' => $idGood,
    'products' => $products,
    'canEdit' => Auth::can('norms.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'norms');
