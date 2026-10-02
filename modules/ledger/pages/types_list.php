<?php
declare(strict_types=1);

$q = trim((string)($_GET['q'] ?? ''));
$lq = ListQuery::resolve([
    'list_id' => 'ledger_types',
    'allowed' => AccountMoveTypesRepository::listSortMap(),
    'default' => 'num',
    'default_dir' => 'asc',
    'per_page' => 100,
]);
$filters = array_merge(['q' => $q], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}
BulkActions::tryHandle('ledger_types', '/account-move-types', $filters);
$result = AccountMoveTypesRepository::list([
    'q' => $q,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);
$names = AccountMoveTypesRepository::names();
$accounts = LedgerAccountsRepository::names();
View::render('types_list', [
    'title' => t('ledger.menu.move_types'),
    'breadcrumbs' => Breadcrumbs::withHome([['label' => t('ledger.menu.move_types'), 'current' => true]]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'names' => $names,
    'accounts' => $accounts,
    'canEdit' => Auth::can('ledger.types'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'ledger');
