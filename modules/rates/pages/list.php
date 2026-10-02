<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$operatorId = (int)($_GET['operator_id'] ?? 0);

$lq = ListQuery::resolve([
    'list_id' => 'rates',
    'allowed' => RatesRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'operator_id' => $operatorId > 0 ? (string)$operatorId : '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('rates', '/rates', $filters);

$result = RatesRepository::list([
    'q' => $q,
    'operator_id' => $operatorId,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$staff = [];
foreach (UsersRepository::listForCopy() as $u) {
    $staff[(int)$u['id']] = trim((string)$u['name']) !== '' ? (string)$u['name'] : (string)$u['login'];
}
$typeNames = ProcedureTypesRepository::names();
$bulkFlash = BulkActions::consumeFlash();

View::render('list', [
    'title' => t('rates.menu.list'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('rates.menu.list'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'operatorId' => $operatorId,
    'staff' => $staff,
    'typeNames' => $typeNames,
    'canEdit' => Auth::can('rates.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
], 'layout', 'rates');
