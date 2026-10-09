<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$q = trim((string)($_GET['q'] ?? ''));
$userId = (int)($_GET['user_id'] ?? 0);
$sign = (int)($_GET['sign'] ?? 0);
$idType = (int)($_GET['id_type'] ?? 0);
$direction = (int)($_GET['direction'] ?? 0);
$yearRaw = $_GET['year'] ?? null;
$monthRaw = $_GET['month'] ?? null;

$canSeeOthers = VacationsRepository::canSeeOthers();
$scopeUserId = $canSeeOthers ? 0 : Auth::userId();
$showUserOnly = !$canSeeOthers;

if (!$canSeeOthers) {
    $userId = Auth::userId();
}

// Defaults like oper: non-privileged → current year; privileged → all years until chosen.
if ($yearRaw === null) {
    $year = $canSeeOthers ? 0 : (int)date('Y');
} elseif ($yearRaw === '' || $yearRaw === 'all') {
    $year = 0;
} else {
    $year = (int)$yearRaw;
}
if ($monthRaw === null || $monthRaw === '' || $monthRaw === 'all') {
    $month = 0;
} else {
    $month = (int)$monthRaw;
}
if ($month < 1 || $month > 12) {
    $month = 0;
}

$lq = ListQuery::resolve([
    'list_id' => 'vacations',
    'allowed' => VacationsRepository::listSortMap(),
    'default' => 'start',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'user_id' => $userId > 0 ? (string)$userId : '',
    'sign' => $sign > 0 ? (string)$sign : '',
    'id_type' => $idType > 0 ? (string)$idType : '',
    'direction' => $direction !== 0 ? (string)$direction : '',
    'year' => $year > 0 ? (string)$year : '',
    'month' => $month > 0 ? (string)$month : '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('vacations', '/vacations', $filters);

$result = VacationsRepository::list([
    'q' => $q,
    'user_id' => $canSeeOthers ? $userId : 0,
    'sign' => $sign,
    'id_type' => $idType,
    'direction' => $direction,
    'year' => $year,
    'month' => $month,
    'show_user_only' => $showUserOnly,
    'scope_user_id' => $scopeUserId,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => [
            'q' => $q,
            'user_id' => $userId > 0 ? $userId : null,
            'sign' => $sign > 0 ? $sign : null,
            'id_type' => $idType > 0 ? $idType : null,
            'direction' => $direction !== 0 ? $direction : null,
            'year' => $year > 0 ? $year : null,
            'month' => $month > 0 ? $month : null,
        ],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
        'can_see_others' => $canSeeOthers,
    ]);
    return;
}

$staff = [];
foreach (UsersRepository::listEnabledStaff() as $u) {
    $staff[(int)$u['id']] = trim((string)$u['name']) !== '' ? (string)$u['name'] : (string)$u['login'];
}
$bulkFlash = BulkActions::consumeFlash();

$periodTypes = [3, 1];
if ($canSeeOthers) {
    $periodTypes[] = 2;
}

View::render('list', [
    'title' => t('vacations.menu.list'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('vacations.menu.list'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'userId' => $userId,
    'sign' => $sign,
    'idType' => $idType,
    'direction' => $direction,
    'year' => $year,
    'month' => $month,
    'years' => VacationsRepository::yearOptions(),
    'periodTypes' => $periodTypes,
    'staff' => $staff,
    'canSeeOthers' => $canSeeOthers,
    'canEdit' => Auth::can('vacations.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
], 'layout', 'vacations');
