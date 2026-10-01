<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '1');
if (!in_array($enabled, ['', '0', '1'], true)) {
    $enabled = '1';
}

$lq = ListQuery::resolve([
    'list_id' => 'users',
    'allowed' => UsersRepository::listSortMap(),
    'default' => 'name',
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

View::render('users', [
    'title' => t('core.menu.users'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'enabled' => $enabled,
    'statuses' => UsersRepository::statusNames(),
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
