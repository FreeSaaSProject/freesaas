<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$pipelines = CrmPipelinesRepository::listPipelines(true);
$defaultPipe = (int)($_GET['pipeline_id'] ?? 0) ?: (int)($pipelines[0]['id'] ?? 0);
$row = $isNew ? [
    'title' => '',
    'pipeline_id' => $defaultPipe,
    'stage_id' => CrmPipelinesRepository::firstStageId($defaultPipe),
    'company_id' => (int)($_GET['company_id'] ?? 0),
    'amount' => 0,
    'currency' => 'RUB',
    'responsible_user_id' => (int)Auth::userId(),
    'source' => '',
    'notes' => '',
    'closed_at' => 0,
] : CrmDealsRepository::find($id);

if (!$isNew && $row === null) {
    http_response_code(404);
    if ($wantsJson) {
        AgentHttp::error(t('core.common.not_found'), 404);
        return;
    }
    View::render('error', ['title' => t('core.common.not_found'), 'message' => ''], 'layout', 'crm');
    return;
}

$message = '';
$error = '';
$links = $isNew ? [] : CrmDealsRepository::contactLinks($id);
$clientIds = array_map('intval', array_column($links, 'client_id'));
$primaryClientId = 0;
foreach ($links as $link) {
    if (!empty($link['is_primary'])) {
        $primaryClientId = (int)$link['client_id'];
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$isNew && (string)($_POST['action'] ?? '') === 'delete') {
            if (!Auth::can('crm.delete')) {
                throw new RuntimeException(t('core.common.forbidden'));
            }
            CrmDealsRepository::delete($id);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'deleted' => true, 'id' => $id]);
                return;
            }
            redirect('/crm/deals');
        }
        $data = CrmUi::patch($row, $_POST, [
            'title', 'pipeline_id', 'stage_id', 'company_id', 'amount', 'currency',
            'responsible_user_id', 'source', 'notes',
        ]);
        if (!$isNew && array_key_exists('pipeline_id', $_POST) && !array_key_exists('stage_id', $_POST)
            && (int)$_POST['pipeline_id'] !== (int)$row['pipeline_id']) {
            $data['stage_id'] = CrmPipelinesRepository::firstStageId((int)$_POST['pipeline_id']);
        }
        $data['pipeline_id'] = (int)$data['pipeline_id'];
        $data['stage_id'] = (int)$data['stage_id'];
        $data['company_id'] = (int)$data['company_id'];
        $data['amount'] = (float)str_replace([' ', ','], ['', '.'], (string)$data['amount']);
        $data['responsible_user_id'] = (int)$data['responsible_user_id'];
        $postedIds = CrmUi::postedClientIds($_POST);
        if (array_key_exists('primary_client_id', $_POST)) {
            $primaryClientId = (int)$_POST['primary_client_id'];
            if ($postedIds === null) {
                $postedIds = $clientIds;
            }
        }
        if ($isNew) {
            $id = CrmDealsRepository::create($data, $postedIds ?? [], $primaryClientId);
            $isNew = false;
        } else {
            CrmDealsRepository::update($id, $data, $postedIds, $primaryClientId);
        }
        $cf = $_POST['cf'] ?? [];
        if (is_array($cf)) {
            CrmCustomFieldsRepository::saveValues('deal', $id, $cf);
        }
        $row = CrmDealsRepository::find($id);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row]);
            return;
        }
        flash_set('ok', t('crm.flash.saved'));
        redirect('/crm/deals/' . $id);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
        $row = array_merge((array)$row, array_intersect_key($_POST, array_flip([
            'title', 'pipeline_id', 'stage_id', 'company_id', 'amount', 'currency',
            'responsible_user_id', 'source', 'notes',
        ])));
    }
}

$stagesByPipe = [];
foreach ($pipelines as $p) {
    $stagesByPipe[(int)$p['id']] = CrmPipelinesRepository::listStages((int)$p['id'], true);
}
$companies = CrmCompaniesRepository::names(true);
$cfDefs = CrmCustomFieldsRepository::listFields('deal', true);
$cfValues = $isNew ? [] : CrmCustomFieldsRepository::valuesFor('deal', $id);
$staff = CrmUi::staffOptions([(int)($row['responsible_user_id'] ?? 0)]);

if ($wantsJson && $_SERVER['REQUEST_METHOD'] === 'GET') {
    AgentHttp::emit([
        'ok' => true,
        'item' => $row,
        'client_ids' => $clientIds,
        'primary_client_id' => $primaryClientId,
        'pipelines' => $pipelines,
        'stages_by_pipeline' => $stagesByPipe,
        'companies' => $companies,
        'staff' => $staff,
        'custom_fields' => $cfDefs,
        'custom_values' => $cfValues,
    ]);
    return;
}

View::render('deal_edit', [
    'title' => $isNew ? t('crm.deals.heading_new') : (string)($row['title'] ?? ''),
    'breadcrumbs' => Breadcrumbs::withHome($isNew ? [
        ['label' => t('crm.deals.title'), 'href' => '/crm/deals'],
        ['label' => t('crm.deals.heading_new'), 'current' => true],
    ] : [
        ['label' => t('crm.deals.title'), 'href' => '/crm/deals'],
        ['label' => (string)($row['title'] ?? ''), 'href' => '/crm/deals/' . $id],
        ['label' => t('core.common.edit'), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'pipelines' => $pipelines,
    'stagesByPipe' => $stagesByPipe,
    'companies' => $companies,
    'staff' => $staff,
    'cfDefs' => $cfDefs,
    'cfValues' => $cfValues,
    'message' => $message,
    'error' => $error,
    'canDelete' => !$isNew && Auth::can('crm.delete'),
], 'layout', 'crm');
