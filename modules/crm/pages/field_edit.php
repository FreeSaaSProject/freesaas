<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = $isNew
    ? ['entity' => 'deal', 'code' => '', 'name' => '', 'field_type' => 'string', 'enums_json' => '', 'sort_order' => 0, 'enabled' => 1]
    : CrmCustomFieldsRepository::find($id);

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
        if (!$isNew && (string)($_POST['action'] ?? '') === 'delete') {
            CrmCustomFieldsRepository::delete($id);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'deleted' => true]);
                return;
            }
            redirect('/crm/fields');
        }
        $data = [
            'entity' => $_POST['entity'] ?? 'deal',
            'code' => $_POST['code'] ?? '',
            'name' => $_POST['name'] ?? '',
            'field_type' => $_POST['field_type'] ?? 'string',
            'enums_json' => $_POST['enums_json'] ?? '',
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
        ];
        if ($isNew) {
            $id = CrmCustomFieldsRepository::create($data);
        } else {
            CrmCustomFieldsRepository::update($id, $data);
        }
        $row = CrmCustomFieldsRepository::find($id);
        $message = t('crm.flash.saved');
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row]);
            return;
        }
        redirect('/crm/fields');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

View::render('field_edit', [
    'title' => $isNew ? t('crm.fields.heading_new') : (string)($row['name'] ?? ''),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('crm.fields.title'), 'href' => '/crm/fields'],
        ['label' => $isNew ? t('crm.fields.heading_new') : (string)($row['name'] ?? ''), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
], 'layout', 'crm');
