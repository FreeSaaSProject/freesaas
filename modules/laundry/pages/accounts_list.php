<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '');
$lq = ListQuery::resolve([
    'list_id' => 'laundry_accounts',
    'allowed' => LaundryAccountsRepository::listSortMap(),
    'default' => 'name',
    'default_dir' => 'asc',
    'per_page' => 50,
]);
$filters = array_merge(['q' => $q, 'enabled' => $enabled], $lq['query']);
BulkActions::tryHandle('laundry_accounts', '/laundry/accounts', $filters);
$result = LaundryAccountsRepository::list([
    'q' => $q,
    'enabled' => $enabled !== '' ? $enabled : null,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'total' => $result['total'], 'items' => $result['items']]);
    return;
}

View::render('accounts_list', [
    'title' => t('laundry.menu.accounts'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('laundry.menu.home'), 'href' => '/laundry'],
        ['label' => t('laundry.menu.accounts'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'enabled' => $enabled,
    'canEdit' => Auth::can('laundry.edit'),
    'sort' => $lq['sort'], 'dir' => $lq['dir'], 'page' => $lq['page'], 'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => null,
], 'layout', 'laundry');
