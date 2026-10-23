<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

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
$staff = (int)($_GET['staff'] ?? 0);
$active = !empty($_GET['active']);
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$dtFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? (int)strtotime($dateFrom . ' 00:00:00') : 0;
// date_to is inclusive (whole day).
$dtTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? (int)strtotime($dateTo . ' 00:00:00') + 86400 : 0;

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

BulkActions::tryHandle('orders', '/orders', $filters);

$result = OrdersRepository::list([
    'q' => $q,
    'status' => $status,
    'id_user' => $idUser > 0 ? $idUser : null,
    'id_promocode' => $idPromocode > 0 ? $idPromocode : null,
    'free_ticket' => $freeTicket === '' ? null : $freeTicket,
    'staff' => $staff,
    'dt_from' => $dtFrom,
    'dt_to' => $dtTo,
    'active' => $active,
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

if ($wantsJson) {
    $agentItems = [];
    foreach ($result['items'] as $it) {
        $dt = (int)($it['dt_event'] ?? 0);
        $it['dt_event_date'] = $dt > 0 ? date('Y-m-d', $dt) : '';
        $it['dt_event_time'] = $dt > 0 ? date('H:i', $dt) : '';
        $agentItems[] = $it;
    }
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $agentItems,
        'filters' => [
            'q' => $q,
            'status' => $status,
            'id_user' => $idUser > 0 ? $idUser : null,
            'id_promocode' => $idPromocode > 0 ? $idPromocode : null,
            'free_ticket' => $freeTicket === '' ? null : $freeTicket,
            'staff' => $staff > 0 ? $staff : null,
            'date_from' => $dtFrom > 0 ? $dateFrom : null,
            'date_to' => $dtTo > 0 ? $dateTo : null,
            'active' => $active ? 1 : null,
        ],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
    ]);
    return;
}

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
    'bulkFlash' => BulkActions::consumeFlash(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('calendar.menu.group'), 'href' => '/calendar'],
        $ordersCrumb,
    ]),
], 'layout', 'orders');
