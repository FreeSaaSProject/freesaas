<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '');
$lq = ListQuery::resolve(['list_id' => 'sheet_accounts', 'allowed' => SheetAccountsRepository::listSortMap(), 'default' => 'name', 'default_dir' => 'asc', 'per_page' => 50]);
$filters = array_merge(['q' => $q, 'enabled' => $enabled], $lq['query']);
BulkActions::tryHandle('sheet_accounts', '/sheets/accounts', $filters);
$result = SheetAccountsRepository::list([
    'q' => $q,
    'enabled' => $enabled === '' ? null : $enabled,
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
            'enabled' => $enabled === '' ? null : $enabled,
        ],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
    ]);
    return;
}

View::render('accounts_list', [
    'title' => t('sheets.menu.accounts'),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'enabled' => $enabled,
    'canEdit' => Auth::can('sheets.edit'),
    'sort' => $lq['sort'], 'dir' => $lq['dir'], 'page' => $lq['page'], 'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'], 'filters' => $filters, 'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'sheets');
