<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));
$lq = ListQuery::resolve([
    'list_id' => 'remittances',
    'allowed' => RemittanceAgreementsRepository::listSortMap(),
    'default' => 'name',
    'default_dir' => 'asc',
    'per_page' => 50,
]);
$filters = array_merge(['q' => $q], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}
BulkActions::tryHandle('remittances', '/remittances', $filters);

$result = RemittanceAgreementsRepository::list([
    'q' => $q,
    'order' => $lq['order_sql'],
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => ['q' => $q],
    ]);
    return;
}

$flash = flash_take();
View::render('list', [
    'title' => t('remittances.menu.list'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('remittances.menu.list'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => !empty($lq['show_all']),
    'filters' => $filters,
    'canEdit' => Auth::can('remittances.manage'),
    'bulkFlash' => $flash,
], 'layout', 'remittances');
