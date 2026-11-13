<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = $isNew ? [
    'name' => '', 'inn' => '', 'kpp' => '', 'phone' => '', 'email' => '',
    'website' => '', 'address' => '', 'notes' => '', 'enabled' => 1,
    'responsible_user_id' => (int)Auth::userId(),
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
$links = $isNew ? [] : CrmCompaniesRepository::contactLinks($id);
$clientIds = array_map('intval', array_column($links, 'client_id'));
$primaryClientId = 0;
foreach ($links as $link) {
    if (!empty($link['is_primary'])) {
        $primaryClientId = (int)$link['client_id'];
        break;
    }
}
$fields = ['name', 'inn', 'kpp', 'phone', 'email', 'website', 'address', 'notes', 'enabled', 'responsible_user_id'];

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
        $data = CrmUi::patch($row, $_POST, $fields);
        $data['enabled'] = !empty($data['enabled']) ? 1 : 0;
        $data['responsible_user_id'] = (int)$data['responsible_user_id'];
        $postedIds = CrmUi::postedClientIds($_POST);
        if (array_key_exists('primary_client_id', $_POST)) {
            $primaryClientId = (int)$_POST['primary_client_id'];
            if ($postedIds === null) {
                $postedIds = $clientIds;
            }
        }
        if ($isNew) {
            $id = CrmCompaniesRepository::create($data, $postedIds ?? [], $primaryClientId);
            $isNew = false;
        } else {
            CrmCompaniesRepository::update($id, $data, $postedIds, $primaryClientId);
        }
        $cf = $_POST['cf'] ?? [];
        if (is_array($cf)) {
            CrmCustomFieldsRepository::saveValues('company', $id, $cf);
        }
        $row = CrmCompaniesRepository::find($id);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row]);
            return;
        }
        flash_set('ok', t('crm.flash.saved'));
        redirect('/crm/companies/' . $id);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
        $row = array_merge((array)$row, array_intersect_key($_POST, array_flip($fields)));
    }
}

$cfDefs = CrmCustomFieldsRepository::listFields('company', true);
$cfValues = $isNew ? [] : CrmCustomFieldsRepository::valuesFor('company', $id);
$staff = CrmUi::staffOptions([(int)($row['responsible_user_id'] ?? 0)]);

if ($wantsJson && $_SERVER['REQUEST_METHOD'] === 'GET') {
    AgentHttp::emit([
        'ok' => true,
        'item' => $row,
        'client_ids' => $clientIds,
        'primary_client_id' => $primaryClientId,
        'staff' => $staff,
        'custom_fields' => $cfDefs,
        'custom_values' => $cfValues,
    ]);
    return;
}

View::render('company_edit', [
    'title' => $isNew ? t('crm.companies.heading_new') : (string)($row['name'] ?? ''),
    'breadcrumbs' => Breadcrumbs::withHome($isNew ? [
        ['label' => t('crm.companies.title'), 'href' => '/crm/companies'],
        ['label' => t('crm.companies.heading_new'), 'current' => true],
    ] : [
        ['label' => t('crm.companies.title'), 'href' => '/crm/companies'],
        ['label' => (string)($row['name'] ?? ''), 'href' => '/crm/companies/' . $id],
        ['label' => t('core.common.edit'), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'staff' => $staff,
    'cfDefs' => $cfDefs,
    'cfValues' => $cfValues,
    'message' => $message,
    'error' => $error,
    'canDelete' => !$isNew && Auth::can('crm.delete'),
], 'layout', 'crm');
