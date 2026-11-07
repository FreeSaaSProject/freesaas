<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = $isNew
    ? ['name' => '', 'kind' => 'sale', 'enabled' => 1, 'sort_order' => 0]
    : CrmPipelinesRepository::findPipeline($id);

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
            'name' => $_POST['name'] ?? '',
            'kind' => $_POST['kind'] ?? 'sale',
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
        ];
        if ($isNew) {
            $id = CrmPipelinesRepository::createPipeline($data);
        } else {
            CrmPipelinesRepository::updatePipeline($id, $data);
        }
        $row = CrmPipelinesRepository::findPipeline($id);
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

if ($wantsJson && $_SERVER['REQUEST_METHOD'] === 'GET') {
    AgentHttp::emit(['ok' => true, 'item' => $row]);
    return;
}

View::render('pipeline_edit', [
    'title' => $isNew ? t('crm.pipelines.heading_new') : (string)($row['name'] ?? ''),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('crm.pipelines.title'), 'href' => '/crm/pipelines'],
        ['label' => $isNew ? t('crm.pipelines.heading_new') : (string)($row['name'] ?? ''), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
], 'layout', 'crm');
