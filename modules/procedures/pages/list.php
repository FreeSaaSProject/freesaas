<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '');
if (!in_array($enabled, ['', '0', '1'], true)) {
    $enabled = '';
}
$typeId = (int)($_GET['type'] ?? 0);
$today = date('Y-m-d');
$dateFrom = (string)($_GET['date_from'] ?? '');
$dateTo = (string)($_GET['date_to'] ?? '');

$dayFocus = $today;
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dayFocus = $dateFrom;
} elseif ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dayFocus = $dateTo;
}
$dayNavActive = ($dateFrom !== '' && $dateFrom === $dateTo && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom));
$isToday = ($dayNavActive && $dateFrom === $today);
$prevDay = date('Y-m-d', strtotime($dayFocus . ' -1 day') ?: time());
$nextDay = date('Y-m-d', strtotime($dayFocus . ' +1 day') ?: time());
$idUser = (int)($_GET['id_user'] ?? 0);
$idGood = (int)($_GET['id_good'] ?? 0);
$idAdmin = (int)($_GET['id_admin'] ?? 0);
$idOperator = (int)($_GET['id_operator'] ?? 0);
$restored = (string)($_GET['restored'] ?? '');
if (!in_array($restored, ['', '0', '1'], true)) {
    $restored = '';
}

$lq = ListQuery::resolve([
    'list_id' => 'procedures',
    'allowed' => ProceduresRepository::listSortMap(),
    'default' => 'dtstart',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'enabled' => $enabled,
    'restored' => $restored,
    'type' => $typeId > 0 ? $typeId : '',
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'id_user' => $idUser > 0 ? $idUser : '',
    'id_good' => $idGood > 0 ? $idGood : '',
    'id_admin' => $idAdmin > 0 ? $idAdmin : '',
    'id_operator' => $idOperator > 0 ? $idOperator : '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('procedures', '/procedures', $filters);

$result = ProceduresRepository::list([
    'q' => $q,
    'enabled' => $enabled === '' ? null : $enabled,
    'restored' => $restored === '' ? null : $restored,
    'type' => $typeId > 0 ? $typeId : null,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'id_user' => $idUser > 0 ? $idUser : null,
    'id_good' => $idGood > 0 ? $idGood : null,
    'id_admin' => $idAdmin > 0 ? $idAdmin : null,
    'id_operator' => $idOperator > 0 ? $idOperator : null,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$staffNames = [];
foreach (UsersRepository::listForCopy() as $u) {
    $label = trim((string)$u['name']);
    if ($label === '') {
        $label = (string)$u['login'];
    }
    $staffNames[(int)$u['id']] = $label;
}

$hasListState = $q !== ''
    || $enabled !== ''
    || $restored !== ''
    || $typeId > 0
    || $dateFrom !== ''
    || $dateTo !== ''
    || $idUser > 0
    || $idGood > 0
    || $idAdmin > 0
    || $idOperator > 0
    || (int)$lq['page'] > 1
    || !empty($lq['show_all'])
    || $lq['sort'] !== 'dtstart'
    || $lq['dir'] !== 'desc'
    || (int)$lq['per_page'] !== 50;

$proceduresCrumb = $hasListState
    ? ['label' => t('procedures.menu.list'), 'href' => '/procedures']
    : ['label' => t('procedures.menu.list'), 'current' => true];

$bulkFlash = BulkActions::consumeFlash();

View::render('list', [
    'title' => t('procedures.menu.list'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'enabled' => $enabled,
    'typeId' => $typeId,
    'dateFrom' => $dateFrom,
    'dateTo' => $dateTo,
    'dayFocus' => $dayFocus,
    'isToday' => $isToday,
    'dayNavActive' => $dayNavActive,
    'prevDay' => $prevDay,
    'nextDay' => $nextDay,
    'today' => $today,
    'idUser' => $idUser,
    'idGood' => $idGood,
    'idAdmin' => $idAdmin,
    'idOperator' => $idOperator,
    'restored' => $restored,
    'typeNames' => ProcedureTypesRepository::names(),
    'staffNames' => $staffNames,
    'canEdit' => Auth::can('procedures.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
    'breadcrumbs' => Breadcrumbs::withHome([
        $proceduresCrumb,
    ]),
], 'layout', 'procedures');
