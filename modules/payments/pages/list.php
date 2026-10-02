<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$userId = (int)($_GET['user_id'] ?? 0);
$typeId = (int)($_GET['type_id'] ?? 0);
$accountRaw = $_GET['account'] ?? '';
$directionRaw = $_GET['direction'] ?? '';
$account = $accountRaw === '' ? null : (int)$accountRaw;
$direction = $directionRaw === '' ? null : (int)$directionRaw;

$canSeeOthers = PaymentsRepository::canSeeOthers();
$scopeUserId = $canSeeOthers ? 0 : Auth::userId();
if (!$canSeeOthers) {
    $userId = Auth::userId();
}

$lq = ListQuery::resolve([
    'list_id' => 'payments',
    'allowed' => PaymentsRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'user_id' => $userId > 0 ? (string)$userId : '',
    'type_id' => $typeId > 0 ? (string)$typeId : '',
    'account' => $accountRaw !== '' ? (string)$account : '',
    'direction' => $directionRaw !== '' ? (string)$direction : '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('payments', '/payments', $filters);

$result = PaymentsRepository::list([
    'q' => $q,
    'user_id' => $canSeeOthers ? $userId : 0,
    'type_id' => $typeId,
    'account' => $account,
    'direction' => $direction,
    'scope_user_id' => $scopeUserId,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$staff = [];
foreach (UsersRepository::listForCopy() as $u) {
    $staff[(int)$u['id']] = trim((string)$u['name']) !== '' ? (string)$u['name'] : (string)$u['login'];
}
$typeNames = PaymentTypesRepository::names();
$bulkFlash = BulkActions::consumeFlash();

View::render('list', [
    'title' => t('payments.menu.list'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('payments.menu.list'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'userId' => $userId,
    'typeId' => $typeId,
    'account' => $account,
    'direction' => $direction,
    'staff' => $staff,
    'typeNames' => $typeNames,
    'canSeeOthers' => $canSeeOthers,
    'canEdit' => Auth::can('payments.edit'),
    'ledgerEnabled' => PaymentsRepository::ledgerEnabled(),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
], 'layout', 'payments');
