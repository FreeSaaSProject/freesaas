<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$row = CrmDealsRepository::find($id);
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
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add_note') {
            $noteId = CrmNotesRepository::create('deal', $id, (string)($_POST['body'] ?? ''));
            $message = t('crm.flash.note_added');
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $id, 'note_id' => $noteId, 'action' => 'add_note']);
                return;
            }
        } elseif ($action === 'move_stage') {
            CrmDealsRepository::moveStage($id, (int)($_POST['stage_id'] ?? 0));
            $row = CrmDealsRepository::find($id);
            $message = t('crm.flash.saved');
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row, 'action' => 'move_stage']);
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

$contacts = CrmDealsRepository::contactLinks($id);
$notes = CrmNotesRepository::listFor('deal', $id);
$history = CrmDealsRepository::stageHistory($id);
$stages = CrmPipelinesRepository::listStages((int)$row['pipeline_id'], true);
$cfDefs = CrmCustomFieldsRepository::listFields('deal', true);
$cfValues = CrmCustomFieldsRepository::valuesFor('deal', $id);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'item' => $row,
        'contacts' => $contacts,
        'notes' => $notes,
        'stage_history' => $history,
        'stages' => $stages,
        'custom_fields' => $cfDefs,
        'custom_values' => $cfValues,
    ]);
    return;
}

View::render('deal_view', [
    'title' => (string)$row['title'],
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('crm.deals.title'), 'href' => '/crm/deals'],
        ['label' => (string)$row['title'], 'current' => true],
    ]),
    'row' => $row,
    'contacts' => $contacts,
    'notes' => $notes,
    'history' => $history,
    'stages' => $stages,
    'cfDefs' => $cfDefs,
    'cfValues' => $cfValues,
    'canEdit' => Auth::can('crm.edit'),
    'message' => $message,
    'error' => $error,
], 'layout', 'crm');
