<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));
$view = (string)($_GET['view'] ?? '') === 'kanban' ? 'kanban' : 'list';
$pipelineId = (int)($_GET['pipeline_id'] ?? 0);
$stageId = (int)($_GET['stage_id'] ?? 0);
$companyId = (int)($_GET['company_id'] ?? 0);
$clientId = (int)($_GET['client_id'] ?? 0);
$responsibleRaw = trim((string)($_GET['responsible'] ?? ''));
$responsibleId = CrmUi::responsibleFilter($responsibleRaw);

$pipelines = CrmPipelinesRepository::pipelineNames(true);
if ($view === 'kanban' && ($pipelineId < 1 || !isset($pipelines[$pipelineId]))) {
    $pipelineId = (int)(array_key_first($pipelines) ?? 0);
}

$lq = ListQuery::resolve([
    'list_id' => 'crm_deals',
    'allowed' => CrmDealsRepository::listSortMap(),
    'default' => 'updated_at',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'view' => $view === 'kanban' ? 'kanban' : '',
    'pipeline_id' => $pipelineId > 0 ? (string)$pipelineId : '',
    'stage_id' => $stageId > 0 ? (string)$stageId : '',
    'company_id' => $companyId > 0 ? (string)$companyId : '',
    'client_id' => $clientId > 0 ? (string)$clientId : '',
    'responsible' => $responsibleRaw,
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('crm_deals', '/crm/deals', $filters);

$staff = CrmUi::staffOptions($responsibleId > 0 ? [$responsibleId] : []);
$jsonFilters = [
    'q' => $q,
    'view' => $view,
    'pipeline_id' => $pipelineId,
    'stage_id' => $stageId,
    'company_id' => $companyId,
    'client_id' => $clientId,
    'responsible' => $responsibleRaw,
    'responsible_user_id' => $responsibleId,
];

if ($view === 'kanban') {
    $columns = $pipelineId > 0 ? CrmDealsRepository::kanban($pipelineId, [
        'q' => $q,
        'company_id' => $companyId,
        'responsible_user_id' => $responsibleId,
    ]) : [];
    foreach ($columns as &$col) {
        $col['items'] = CrmUi::withResponsibleNames($col['items']);
    }
    unset($col);
    if ($wantsJson) {
        AgentHttp::emit([
            'ok' => true,
            'view' => 'kanban',
            'pipeline_id' => $pipelineId,
            'columns' => array_map(static function (array $c): array {
                return [
                    'stage_id' => (int)$c['stage']['id'],
                    'stage_name' => (string)$c['stage']['name'],
                    'stage_code' => (string)$c['stage']['code'],
                    'semantic' => (string)$c['stage']['semantic'],
                    'count' => $c['count'],
                    'sums' => $c['sums'],
                    'items' => $c['items'],
                ];
            }, $columns),
            'filters' => $jsonFilters,
            'pipelines' => $pipelines,
        ]);
        return;
    }
    View::render('deals_kanban', [
        'title' => t('crm.deals.title'),
        'breadcrumbs' => Breadcrumbs::withHome([
            ['label' => t('crm.deals.title'), 'current' => true],
        ]),
        'columns' => $columns,
        'q' => $q,
        'pipelineId' => $pipelineId,
        'pipelines' => $pipelines,
        'responsibleRaw' => $responsibleRaw,
        'staff' => $staff,
        'filters' => $filters,
        'canEdit' => Auth::can('crm.edit'),
    ], 'layout', 'crm');
    return;
}

$result = CrmDealsRepository::list([
    'q' => $q,
    'pipeline_id' => $pipelineId,
    'stage_id' => $stageId,
    'company_id' => $companyId,
    'client_id' => $clientId,
    'responsible_user_id' => $responsibleId,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);
$items = CrmUi::withResponsibleNames($result['items']);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'view' => 'list',
        'total' => $result['total'],
        'items' => $items,
        'filters' => $jsonFilters,
        'pipelines' => $pipelines,
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
    ]);
    return;
}

View::render('deals_list', [
    'title' => t('crm.deals.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('crm.deals.title'), 'current' => true],
    ]),
    'items' => $items,
    'total' => $result['total'],
    'q' => $q,
    'pipelineId' => $pipelineId,
    'stageId' => $stageId,
    'pipelines' => $pipelines,
    'responsibleRaw' => $responsibleRaw,
    'staff' => $staff,
    'canEdit' => Auth::can('crm.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'crm');
