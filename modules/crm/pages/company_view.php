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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::can('crm.edit')) {
    if ($wantsJson) {
        AgentHttp::error(t('core.common.forbidden'), 403);
        return;
    }
    $error = t('core.common.forbidden');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');
        $out = ['ok' => true, 'id' => $id, 'action' => $action];
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($action === 'add_note') {
            $out['note_id'] = CrmNotesRepository::create('company', $id, (string)($_POST['body'] ?? ''));
            $message = t('crm.flash.note_added');
        } elseif ($action === 'add_contact') {
            $res = CrmContactResolver::resolve($_POST);
            CrmCompaniesRepository::addContact($id, $res['client_id'], (string)($_POST['role'] ?? ''), !empty($_POST['is_primary']));
            $out['client_id'] = $res['client_id'];
            $out['client_created'] = $res['created'];
            $message = t('crm.flash.contact_added');
        } elseif ($action === 'remove_contact') {
            CrmCompaniesRepository::removeContact($id, $clientId);
            $message = t('crm.flash.contact_removed');
        } elseif ($action === 'set_contact_role') {
            CrmCompaniesRepository::setContactRole($id, $clientId, (string)($_POST['role'] ?? ''));
            $message = t('crm.flash.saved');
        } elseif ($action === 'set_primary_contact') {
            CrmCompaniesRepository::setPrimaryContact($id, $clientId);
            $message = t('crm.flash.saved');
        } else {
            throw new InvalidArgumentException(t('crm.err.unknown_action'));
        }
        if ($wantsJson) {
            $out['contacts'] = CrmCompaniesRepository::contactLinks($id);
            AgentHttp::emit($out);
            return;
        }
        flash_set('ok', $message);
        redirect('/crm/companies/' . $id);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

$flash = flash_take();
if ($flash !== null && $message === '' && $error === '') {
    $message = (string)$flash['message'];
}
$row = CrmUi::withResponsibleNames([$row])[0];
$contacts = CrmContactResolver::withChannels(CrmCompaniesRepository::contactLinks($id));
$notes = CrmNotesRepository::listFor('company', $id);
$noteAuthors = CrmUi::userNames(array_column($notes, 'author_user_id'));
$cfDefs = CrmCustomFieldsRepository::listFields('company', true);
$cfValues = CrmCustomFieldsRepository::valuesFor('company', $id);
$deals = CrmUi::withResponsibleNames(CrmDealsRepository::list(['company_id' => $id, 'limit' => 50, 'offset' => 0])['items']);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'item' => $row,
        'contacts' => $contacts,
        'notes' => $notes,
        'custom_fields' => $cfDefs,
        'custom_values' => $cfValues,
        'deals' => $deals,
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
    'noteAuthors' => $noteAuthors,
    'cfDefs' => $cfDefs,
    'cfValues' => $cfValues,
    'deals' => $deals,
    'canEdit' => Auth::can('crm.edit'),
    'canCreateClient' => Auth::can('clients.edit'),
    'message' => $message,
    'error' => $error,
], 'layout', 'crm');
