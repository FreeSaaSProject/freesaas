<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$status = trim((string)($_GET['status'] ?? ''));
$lq = ListQuery::resolve([
    'list_id' => 'laundry_shipments',
    'allowed' => ['id' => 'ID', 'dt' => 'DT'],
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);
$result = LaundryShipmentsRepository::list([
    'status' => $status,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
]);
$accounts = LaundryAccountsRepository::names(false);
$types = LaundryItemTypesRepository::names(false);
$message = isset($_GET['saved']) ? t('core.common.saved') : '';

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'total' => $result['total'], 'items' => $result['items']]);
    return;
}

View::render('shipments_list', [
    'title' => t('laundry.menu.shipments'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('laundry.menu.home'), 'href' => '/laundry'],
        ['label' => t('laundry.menu.shipments'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'status' => $status,
    'accounts' => $accounts,
    'types' => $types,
    'message' => $message,
    'canShip' => Auth::can('laundry.ship'),
    'sort' => $lq['sort'], 'dir' => $lq['dir'], 'page' => $lq['page'], 'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => ['status' => $status],
], 'layout', 'laundry');
