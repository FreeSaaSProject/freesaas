<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '');
$lq = ListQuery::resolve([
    'list_id' => 'laundry_types',
    'allowed' => LaundryItemTypesRepository::listSortMap(),
    'default' => 'sort',
    'default_dir' => 'asc',
    'per_page' => 50,
]);
$filters = array_merge(['q' => $q, 'enabled' => $enabled], $lq['query']);
BulkActions::tryHandle('laundry_types', '/laundry/types', $filters);
$result = LaundryItemTypesRepository::list([
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

View::render('types_list', [
    'title' => t('laundry.menu.types'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('laundry.menu.home'), 'href' => '/laundry'],
        ['label' => t('laundry.menu.settings'), 'href' => '/laundry/settings'],
        ['label' => t('laundry.menu.types'), 'current' => true],
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
