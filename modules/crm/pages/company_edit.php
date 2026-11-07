<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = $isNew ? [
    'name' => '', 'inn' => '', 'kpp' => '', 'phone' => '', 'email' => '',
    'website' => '', 'address' => '', 'notes' => '', 'enabled' => 1,
    'responsible_user_id' => 0,
] : CrmCompaniesRepository::find($id);

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
$clientIds = $isNew ? [] : array_column(CrmCompaniesRepository::contactLinks($id), 'client_id');
$primaryClientId = 0;
foreach ($isNew ? [] : CrmCompaniesRepository::contactLinks($id) as $link) {
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
            CrmCompaniesRepository::delete($id);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'deleted' => true, 'id' => $id]);
                return;
            }
            redirect('/crm/companies');
        }
        $data = [
            'name' => $_POST['name'] ?? '',
            'inn' => $_POST['inn'] ?? '',
            'kpp' => $_POST['kpp'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'email' => $_POST['email'] ?? '',
            'website' => $_POST['website'] ?? '',
            'address' => $_POST['address'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
            'responsible_user_id' => (int)($_POST['responsible_user_id'] ?? 0),
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
            $id = CrmCompaniesRepository::create($data, $clientIds, $primaryClientId);
            $isNew = false;
        } else {
            CrmCompaniesRepository::update($id, $data, $clientIds, $primaryClientId);
        }
        $cf = $_POST['cf'] ?? [];
        if (is_array($cf)) {
            CrmCustomFieldsRepository::saveValues('company', $id, $cf);
        }
        $row = CrmCompaniesRepository::find($id);
        $message = t('crm.flash.saved');
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row]);
            return;
        }
        if ((string)($_POST['action'] ?? '') !== 'stay') {
            redirect('/crm/companies/' . $id);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

$cfDefs = CrmCustomFieldsRepository::listFields('company', true);
$cfValues = $isNew ? [] : CrmCustomFieldsRepository::valuesFor('company', $id);

if ($wantsJson && $_SERVER['REQUEST_METHOD'] === 'GET') {
    AgentHttp::emit([
        'ok' => true,
        'item' => $row,
        'client_ids' => $clientIds,
        'primary_client_id' => $primaryClientId,
        'custom_fields' => $cfDefs,
        'custom_values' => $cfValues,
    ]);
    return;
}

View::render('company_edit', [
    'title' => $isNew ? t('crm.companies.heading_new') : (string)($row['name'] ?? ''),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('crm.companies.title'), 'href' => '/crm/companies'],
        ['label' => $isNew ? t('crm.companies.heading_new') : (string)($row['name'] ?? ''), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'clientIds' => $clientIds,
    'primaryClientId' => $primaryClientId,
    'cfDefs' => $cfDefs,
    'cfValues' => $cfValues,
    'message' => $message,
    'error' => $error,
    'canDelete' => !$isNew && Auth::can('crm.delete'),
], 'layout', 'crm');
