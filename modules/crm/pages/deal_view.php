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
        if ($action === 'add_note') {
            $out['note_id'] = CrmNotesRepository::create('deal', $id, (string)($_POST['body'] ?? ''));
            $message = t('crm.flash.note_added');
        } elseif ($action === 'move_stage') {
            CrmDealsRepository::moveStage($id, (int)($_POST['stage_id'] ?? 0));
            $message = t('crm.flash.saved');
        } elseif ($action === 'add_contact') {
            $res = CrmContactResolver::resolve($_POST);
            CrmDealsRepository::addContact($id, $res['client_id'], !empty($_POST['is_primary']));
            $companyId = (int)$row['company_id'];
            if ($companyId > 0 && !empty($_POST['link_company'])) {
                CrmCompaniesRepository::addContact($companyId, $res['client_id'], (string)($_POST['role'] ?? ''));
            }
            $out['client_id'] = $res['client_id'];
            $out['client_created'] = $res['created'];
            $message = t('crm.flash.contact_added');
        } elseif ($action === 'remove_contact') {
            CrmDealsRepository::removeContact($id, (int)($_POST['client_id'] ?? 0));
            $message = t('crm.flash.contact_removed');
        } elseif ($action === 'set_primary_contact') {
            CrmDealsRepository::addContact($id, (int)($_POST['client_id'] ?? 0), true);
            $message = t('crm.flash.saved');
        } elseif ($action === 'add_ticket') {
            $out['ticket_id'] = CrmTickets::createForDeal($row, $_POST);
            $message = t('crm.flash.ticket_created');
        } elseif ($action === 'link_ticket') {
            $tid = (int)preg_replace('/\D+/', '', (string)($_POST['ticket_id'] ?? ''));
            CrmTickets::link($id, $tid);
            $out['ticket_id'] = $tid;
            $message = t('crm.flash.ticket_linked');
        } elseif ($action === 'send_poll') {
            $res = CrmPolls::send($row, $_POST);
            $out += $res;
            $message = t('crm.flash.poll_sent', ['url' => $res['url']]);
        } elseif ($action === 'refill_poll') {
            $res = CrmPolls::refill($id, (int)($_POST['invite_id'] ?? 0), (int)($_POST['expire_days'] ?? 14));
            $out += $res;
            $message = t('crm.flash.poll_refill', ['url' => $res['url']]);
        } elseif ($action === 'unlink_poll') {
            CrmLinksRepository::remove('deal', $id, CrmPolls::TARGET, (int)($_POST['invite_id'] ?? 0));
            $message = t('crm.flash.poll_unlinked');
        } elseif ($action === 'unlink_ticket') {
            CrmLinksRepository::remove('deal', $id, CrmTickets::TARGET, (int)($_POST['ticket_id'] ?? 0));
            $message = t('crm.flash.ticket_unlinked');
        } else {
            throw new InvalidArgumentException(t('crm.err.unknown_action'));
        }
        $row = CrmDealsRepository::find($id) ?? $row;
        if ($wantsJson) {
            $out['item'] = CrmUi::withResponsibleNames([$row])[0];
            AgentHttp::emit($out);
            return;
        }
        flash_set('ok', $message);
        redirect('/crm/deals/' . $id);
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
$contacts = CrmContactResolver::withChannels(CrmDealsRepository::contactLinks($id));
$companyContacts = (int)$row['company_id'] > 0 ? CrmCompaniesRepository::contactLinks((int)$row['company_id']) : [];
$roles = [];
foreach ($companyContacts as $cc) {
    $roles[(int)$cc['client_id']] = (string)($cc['role'] ?? '');
}
foreach ($contacts as &$c) {
    $c['role'] = $roles[(int)$c['client_id']] ?? '';
}
unset($c);
$onDeal = array_flip(array_map('intval', array_column($contacts, 'client_id')));
$companyCandidates = array_values(array_filter($companyContacts, static function (array $cc) use ($onDeal): bool {
    return !isset($onDeal[(int)$cc['client_id']]);
}));
$stages = CrmPipelinesRepository::listStages((int)$row['pipeline_id'], true);
$cfDefs = CrmCustomFieldsRepository::listFields('deal', true);
$cfValues = CrmCustomFieldsRepository::valuesFor('deal', $id);
$timeline = CrmTimeline::forDeal($row);
$tickets = array_values(array_map(static fn(array $it): array => $it['ticket'], array_filter(
    $timeline,
    static fn(array $it): bool => ($it['type'] ?? '') === 'ticket' && isset($it['ticket'])
)));
$polls = array_values(array_map(static fn(array $it): array => $it['poll'], array_filter(
    $timeline,
    static fn(array $it): bool => ($it['type'] ?? '') === 'poll' && isset($it['poll'])
)));

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'item' => $row,
        'tickets' => $tickets,
        'polls' => $polls,
        'contacts' => $contacts,
        'company_contacts' => $companyContacts,
        'stages' => $stages,
        'custom_fields' => $cfDefs,
        'custom_values' => $cfValues,
        'timeline' => $timeline,
        'notes' => CrmNotesRepository::listFor('deal', $id),
        'stage_history' => CrmDealsRepository::stageHistory($id),
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
    'companyCandidates' => $companyCandidates,
    'stages' => $stages,
    'cfDefs' => $cfDefs,
    'cfValues' => $cfValues,
    'timeline' => $timeline,
    'tickets' => $tickets,
    'polls' => $polls,
    'pollsAvailable' => CrmPolls::available() && Auth::can('poll_invites.edit'),
    'pollOptions' => CrmPolls::pollOptions(),
    'ticketsAvailable' => CrmTickets::available() && Auth::can('core.news'),
    'staff' => CrmUi::staffOptions([(int)$row['responsible_user_id']]),
    'canEdit' => Auth::can('crm.edit'),
    'canCreateClient' => Auth::can('clients.edit'),
    'message' => $message,
    'error' => $error,
], 'layout', 'crm');
