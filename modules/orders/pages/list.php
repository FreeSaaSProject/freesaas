<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'all'));
$allowedStatus = array_keys(OrdersRepository::statusFilterOptions());
if (!in_array($status, $allowedStatus, true)) {
    $status = 'all';
}
$idUser = (int)($_GET['id_user'] ?? 0);
$idPromocode = (int)($_GET['id_promocode'] ?? 0);
$freeTicket = (string)($_GET['free_ticket'] ?? '');
if (!in_array($freeTicket, ['', '0', '1'], true)) {
    $freeTicket = '';
}

$lq = ListQuery::resolve([
    'list_id' => 'orders',
    'allowed' => OrdersRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'status' => $status,
    'id_user' => $idUser > 0 ? $idUser : '',
    'id_promocode' => $idPromocode > 0 ? $idPromocode : '',
    'free_ticket' => $freeTicket,
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

$result = OrdersRepository::list([
    'q' => $q,
    'status' => $status,
    'id_user' => $idUser > 0 ? $idUser : null,
    'id_promocode' => $idPromocode > 0 ? $idPromocode : null,
    'free_ticket' => $freeTicket === '' ? null : $freeTicket,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$hasListState = $q !== ''
    || $status !== 'all'
    || $idUser > 0
    || $idPromocode > 0
    || $freeTicket !== ''
    || (int)$lq['page'] > 1
    || !empty($lq['show_all'])
    || $lq['sort'] !== 'id'
    || $lq['dir'] !== 'desc'
    || (int)$lq['per_page'] !== 50;

$ordersCrumb = $hasListState
    ? ['label' => t('orders.menu.list'), 'href' => '/orders']
    : ['label' => t('orders.menu.list'), 'current' => true];

View::render('list', [
    'title' => t('orders.menu.list'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'status' => $status,
    'statusOptions' => OrdersRepository::statusFilterOptions(),
    'idUser' => $idUser,
    'idPromocode' => $idPromocode,
    'freeTicket' => $freeTicket,
    'canEdit' => Auth::can('orders.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'breadcrumbs' => Breadcrumbs::withHome([
        $ordersCrumb,
    ]),
], 'layout', 'orders');
