<?php
declare(strict_types=1);

$path = '/users';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'toggle_user') {
            UsersRepository::setEnabled(
                (int)($_POST['id'] ?? 0),
                !empty($_POST['enabled'])
            );
        }
    } catch (Throwable $e) {
        // fall through — same pattern as catalog browse
    }
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    redirect($path . ($qs !== '' ? '?' . $qs : ''));
    return;
}

$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '1');
if (!in_array($enabled, ['', '0', '1'], true)) {
    $enabled = '1';
}

$sortMap = UsersRepository::listSortMap();
$lq = ListQuery::resolve([
    'list_id' => 'users',
    'allowed' => $sortMap,
    'default' => isset($sortMap['num']) ? 'num' : 'name',
    'default_dir' => 'asc',
    'per_page' => 50,
]);

$result = UsersRepository::list([
    'q' => $q,
    'enabled' => $enabled === '' ? null : $enabled,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$filters = array_merge([
    'q' => $q,
    'enabled' => $enabled,
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

$list = EventBus::emit('core.users.list', [
    'items' => $result['items'],
    'extra_columns' => [],
]);
$items = is_array($list) && isset($list['items']) && is_array($list['items'])
    ? $list['items']
    : $result['items'];
$extraColumns = is_array($list) && isset($list['extra_columns']) && is_array($list['extra_columns'])
    ? $list['extra_columns']
    : [];

View::render('users', [
    'title' => t('core.menu.users'),
    'items' => $items,
    'total' => $result['total'],
    'q' => $q,
    'enabled' => $enabled,
    'statuses' => UsersRepository::statusNames(),
    'extraColumns' => $extraColumns,
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.users'), 'current' => true],
    ]),
]);
