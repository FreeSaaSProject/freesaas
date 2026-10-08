<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));
$lq = ListQuery::resolve([
    'list_id' => 'ledger_accounts',
    'allowed' => LedgerAccountsRepository::listSortMap(),
    'default' => 'name',
    'default_dir' => 'asc',
    'per_page' => 50,
]);
$filters = array_merge(['q' => $q], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}
BulkActions::tryHandle('ledger_accounts', '/accounts', $filters);
$result = LedgerAccountsRepository::list([
    'q' => $q,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);
$accountIds = array_map(static function (array $r): int {
    return (int)$r['id'];
}, $result['items']);
$badgePayload = EventBus::emit('ledger.accounts.badges', ['ids' => $accountIds, 'by_id' => []]);
$accountBadges = is_array($badgePayload['by_id'] ?? null) ? $badgePayload['by_id'] : [];
if ($accountBadges !== [] && ModuleManager::isEnabled('banks')) {
    Assets::need('banks');
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => ['q' => $q],
        'badges' => $accountBadges,
    ]);
    return;
}
View::render('accounts_list', [
    'title' => t('ledger.menu.accounts'),
    'breadcrumbs' => Breadcrumbs::withHome([['label' => t('ledger.menu.accounts'), 'current' => true]]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'canEdit' => Auth::can('ledger.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
    'accountBadges' => $accountBadges,
], 'layout', 'ledger');
