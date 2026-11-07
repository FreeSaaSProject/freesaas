<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '1');

$lq = ListQuery::resolve([
    'list_id' => 'crm_companies',
    'allowed' => CrmCompaniesRepository::listSortMap(),
    'default' => 'name',
    'default_dir' => 'asc',
    'per_page' => 50,
]);

$filters = array_merge(['q' => $q, 'enabled' => $enabled], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('crm_companies', '/crm/companies', $filters);

$result = CrmCompaniesRepository::list([
    'q' => $q,
    'enabled' => $enabled,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => ['q' => $q, 'enabled' => $enabled],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
    ]);
    return;
}

View::render('companies_list', [
    'title' => t('crm.companies.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('crm.companies.title'), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'enabled' => $enabled,
    'canEdit' => Auth::can('crm.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'crm');
