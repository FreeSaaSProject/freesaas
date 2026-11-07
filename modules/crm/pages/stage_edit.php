<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$pipelineId = (int)($_GET['pipeline_id'] ?? $_POST['pipeline_id'] ?? 0);
$row = $isNew
    ? ['pipeline_id' => $pipelineId, 'name' => '', 'code' => '', 'semantic' => 'P', 'sort_order' => 0, 'enabled' => 1]
    : CrmPipelinesRepository::findStage($id);

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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = [
            'pipeline_id' => (int)($_POST['pipeline_id'] ?? $pipelineId),
            'name' => $_POST['name'] ?? '',
            'code' => $_POST['code'] ?? '',
            'semantic' => $_POST['semantic'] ?? 'P',
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
        ];
        if ($isNew) {
            $id = CrmPipelinesRepository::createStage($data);
        } else {
            CrmPipelinesRepository::updateStage($id, $data);
        }
        $row = CrmPipelinesRepository::findStage($id);
        $message = t('crm.flash.saved');
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row]);
            return;
        }
        redirect('/crm/pipelines');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

View::render('stage_edit', [
    'title' => $isNew ? t('crm.stages.new') : (string)($row['name'] ?? ''),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('crm.pipelines.title'), 'href' => '/crm/pipelines'],
        ['label' => $isNew ? t('crm.stages.new') : (string)($row['name'] ?? ''), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'pipelines' => CrmPipelinesRepository::pipelineNames(false),
    'message' => $message,
    'error' => $error,
], 'layout', 'crm');
