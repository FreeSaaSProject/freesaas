<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$row = CrmCompaniesRepository::find($id);
if ($row === null) {
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::can('crm.edit')) {
    try {
        if ((string)($_POST['action'] ?? '') === 'add_note') {
            $noteId = CrmNotesRepository::create('company', $id, (string)($_POST['body'] ?? ''));
            $message = t('crm.flash.note_added');
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $id, 'note_id' => $noteId, 'action' => 'add_note']);
                return;
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

$contacts = CrmCompaniesRepository::contactLinks($id);
$notes = CrmNotesRepository::listFor('company', $id);
$cfDefs = CrmCustomFieldsRepository::listFields('company', true);
$cfValues = CrmCustomFieldsRepository::valuesFor('company', $id);
$deals = CrmDealsRepository::list(['company_id' => $id, 'limit' => 50, 'offset' => 0]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'item' => $row,
        'contacts' => $contacts,
        'notes' => $notes,
        'custom_fields' => $cfDefs,
        'custom_values' => $cfValues,
        'deals' => $deals['items'],
    ]);
    return;
}

View::render('company_view', [
    'title' => (string)$row['name'],
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('crm.companies.title'), 'href' => '/crm/companies'],
        ['label' => (string)$row['name'], 'current' => true],
    ]),
    'row' => $row,
    'contacts' => $contacts,
    'notes' => $notes,
    'cfDefs' => $cfDefs,
    'cfValues' => $cfValues,
    'deals' => $deals['items'],
    'canEdit' => Auth::can('crm.edit'),
    'message' => $message,
    'error' => $error,
], 'layout', 'crm');
