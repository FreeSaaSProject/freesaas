<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$q = trim((string)($_GET['q'] ?? ''));
$userId = (int)($_GET['user_id'] ?? 0);
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$lq = ListQuery::resolve([
    'list_id' => 'workweeks',
    'allowed' => WorkweeksRepository::listSortMap(),
    'default' => 'dt',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'user_id' => $userId > 0 ? (string)$userId : '',
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('workweeks', '/workweeks', $filters);

$result = WorkweeksRepository::list([
    'q' => $q,
    'user_id' => $userId,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$staffOptions = [];
foreach (UsersRepository::listForCopy() as $u) {
    $label = trim((string)$u['name']);
    if ($label === '') {
        $label = (string)$u['login'];
    }
    $staffOptions[(int)$u['id']] = $label;
}

$tableNames = WorkweeksRepository::tablesActive()
    ? TablesRepository::names(false)
    : [];

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => WorkweeksRepository::listForAgent($result['items']),
        'filters' => [
            'q' => $q,
            'user_id' => $userId > 0 ? $userId : null,
            'date_from' => $dateFrom !== '' ? $dateFrom : null,
            'date_to' => $dateTo !== '' ? $dateTo : null,
        ],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
        'tables_on' => WorkweeksRepository::tablesActive(),
    ]);
    return;
}

View::render('list', [
    'title' => t('workweeks.menu.list'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'userId' => $userId,
    'dateFrom' => $dateFrom,
    'dateTo' => $dateTo,
    'staffOptions' => $staffOptions,
    'tableNames' => $tableNames,
    'tablesOn' => WorkweeksRepository::tablesActive(),
    'canEdit' => Auth::can('workweeks.edit'),
    'canAssign' => WorkweeksRepository::canAssign(),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('workweeks.menu.list'), 'current' => true],
    ]),
], 'layout', 'workweeks');
