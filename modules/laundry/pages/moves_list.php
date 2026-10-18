<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$idAccount = (int)($_GET['id_account'] ?? 0);
$idType = (int)($_GET['id_item_type'] ?? 0);
$lq = ListQuery::resolve([
    'list_id' => 'laundry_moves',
    'allowed' => ['id' => 'ID', 'dt' => 'DT', 'summ' => 'SUMM'],
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);
$result = LaundryMovesRepository::list([
    'id_account' => $idAccount > 0 ? $idAccount : null,
    'id_item_type' => $idType > 0 ? $idType : null,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);
$accounts = LaundryAccountsRepository::names(false);
$types = LaundryItemTypesRepository::names(false);
$matrix = LaundryMovesRepository::balanceMatrix();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'balances' => $matrix,
        'accounts' => $accounts,
        'types' => $types,
    ]);
    return;
}

View::render('moves_list', [
    'title' => t('laundry.menu.home'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('laundry.menu.home'), 'href' => '/laundry'],
        ['label' => t('laundry.menu.moves'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'idAccount' => $idAccount,
    'idType' => $idType,
    'accounts' => $accounts,
    'types' => $types,
    'matrix' => $matrix,
    'typeList' => LaundryItemTypesRepository::listEnabled(),
    'sort' => $lq['sort'], 'dir' => $lq['dir'], 'page' => $lq['page'], 'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => ['id_account' => $idAccount ?: '', 'id_item_type' => $idType ?: ''],
], 'layout', 'laundry');
