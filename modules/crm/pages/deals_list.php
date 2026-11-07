<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));
$pipelineId = (int)($_GET['pipeline_id'] ?? 0);
$stageId = (int)($_GET['stage_id'] ?? 0);
$companyId = (int)($_GET['company_id'] ?? 0);
$clientId = (int)($_GET['client_id'] ?? 0);

$lq = ListQuery::resolve([
    'list_id' => 'crm_deals',
    'allowed' => CrmDealsRepository::listSortMap(),
    'default' => 'updated_at',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_merge([
    'q' => $q,
    'pipeline_id' => $pipelineId > 0 ? (string)$pipelineId : '',
    'stage_id' => $stageId > 0 ? (string)$stageId : '',
    'company_id' => $companyId > 0 ? (string)$companyId : '',
    'client_id' => $clientId > 0 ? (string)$clientId : '',
], $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle('crm_deals', '/crm/deals', $filters);

$result = CrmDealsRepository::list([
    'q' => $q,
    'pipeline_id' => $pipelineId,
    'stage_id' => $stageId,
    'company_id' => $companyId,
    'client_id' => $clientId,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

$pipelines = CrmPipelinesRepository::pipelineNames(true);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => [
            'q' => $q,
            'pipeline_id' => $pipelineId,
            'stage_id' => $stageId,
            'company_id' => $companyId,
            'client_id' => $clientId,
        ],
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
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'pipelineId' => $pipelineId,
    'stageId' => $stageId,
    'pipelines' => $pipelines,
    'canEdit' => Auth::can('crm.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'crm');
