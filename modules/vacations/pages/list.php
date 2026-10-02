<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$userId = (int)($_GET['user_id'] ?? 0);
$sign = (int)($_GET['sign'] ?? 0);
$canSeeOthers = VacationsRepository::canSeeOthers();
$scopeUserId = $canSeeOthers ? 0 : Auth::userId();
if (!$canSeeOthers) {
    $userId = Auth::userId();
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
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('vacations', '/vacations', $filters);

$result = VacationsRepository::list([
    'q' => $q,
    'user_id' => $canSeeOthers ? $userId : 0,
    'sign' => $sign,
    'scope_user_id' => $scopeUserId,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$staff = [];
foreach (UsersRepository::listForCopy() as $u) {
    $staff[(int)$u['id']] = trim((string)$u['name']) !== '' ? (string)$u['name'] : (string)$u['login'];
}
$bulkFlash = BulkActions::consumeFlash();

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
