<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$pipelines = CrmPipelinesRepository::listPipelines(true);
$defaultPipe = $pipelines[0]['id'] ?? 0;
$row = $isNew ? [
    'title' => '',
    'pipeline_id' => (int)$defaultPipe,
    'stage_id' => CrmPipelinesRepository::firstStageId((int)$defaultPipe),
    'company_id' => 0,
    'amount' => 0,
    'currency' => 'RUB',
    'responsible_user_id' => 0,
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
$clientIds = $isNew ? [] : array_column(CrmDealsRepository::contactLinks($id), 'client_id');
$primaryClientId = 0;
foreach ($isNew ? [] : CrmDealsRepository::contactLinks($id) as $link) {
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
        $data = [
            'title' => $_POST['title'] ?? '',
            'pipeline_id' => (int)($_POST['pipeline_id'] ?? 0),
            'stage_id' => (int)($_POST['stage_id'] ?? 0),
            'company_id' => (int)($_POST['company_id'] ?? 0),
            'amount' => (float)($_POST['amount'] ?? 0),
            'currency' => $_POST['currency'] ?? 'RUB',
            'responsible_user_id' => (int)($_POST['responsible_user_id'] ?? 0),
            'source' => $_POST['source'] ?? '',
            'notes' => $_POST['notes'] ?? '',
        ];
        $rawIds = (string)($_POST['client_ids'] ?? '');
        $clientIds = [];
        foreach (preg_split('/[\s,;]+/', $rawIds) ?: [] as $part) {
            $cid = (int)$part;
            if ($cid > 0) {
                $clientIds[] = $cid;
            }
        }
        $primaryClientId = (int)($_POST['primary_client_id'] ?? 0);
        if ($isNew) {
            $id = CrmDealsRepository::create($data, $clientIds, $primaryClientId);
            $isNew = false;
        } else {
            CrmDealsRepository::update($id, $data, $clientIds, $primaryClientId);
        }
        $cf = $_POST['cf'] ?? [];
        if (is_array($cf)) {
            CrmCustomFieldsRepository::saveValues('deal', $id, $cf);
        }
        $row = CrmDealsRepository::find($id);
        $message = t('crm.flash.saved');
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row]);
            return;
        }
        redirect('/crm/deals/' . $id);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

$stagesByPipe = [];
foreach ($pipelines as $p) {
    $stagesByPipe[(int)$p['id']] = CrmPipelinesRepository::listStages((int)$p['id'], true);
}
$companies = CrmCompaniesRepository::names(true);
$cfDefs = CrmCustomFieldsRepository::listFields('deal', true);
$cfValues = $isNew ? [] : CrmCustomFieldsRepository::valuesFor('deal', $id);

if ($wantsJson && $_SERVER['REQUEST_METHOD'] === 'GET') {
    AgentHttp::emit([
        'ok' => true,
        'item' => $row,
        'client_ids' => $clientIds,
        'primary_client_id' => $primaryClientId,
        'pipelines' => $pipelines,
        'stages_by_pipeline' => $stagesByPipe,
        'companies' => $companies,
        'custom_fields' => $cfDefs,
        'custom_values' => $cfValues,
    ]);
    return;
}

View::render('deal_edit', [
    'title' => $isNew ? t('crm.deals.heading_new') : (string)($row['title'] ?? ''),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('crm.deals.title'), 'href' => '/crm/deals'],
        ['label' => $isNew ? t('crm.deals.heading_new') : (string)($row['title'] ?? ''), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'pipelines' => $pipelines,
    'stagesByPipe' => $stagesByPipe,
    'companies' => $companies,
    'clientIds' => $clientIds,
    'primaryClientId' => $primaryClientId,
    'cfDefs' => $cfDefs,
    'cfValues' => $cfValues,
    'message' => $message,
    'error' => $error,
    'canDelete' => !$isNew && Auth::can('crm.delete'),
], 'layout', 'crm');
