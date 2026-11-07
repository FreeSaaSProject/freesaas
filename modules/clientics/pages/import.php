<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';
$report = null;
$from = (string)($_POST['from'] ?? $_GET['from'] ?? date('Y-m-d', strtotime('-7 days')));
$to = (string)($_POST['to'] ?? $_GET['to'] ?? date('Y-m-d'));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $dryRun = !empty($_POST['dry_run']);
    $from = (string)($_POST['from'] ?? $from);
    $to = (string)($_POST['to'] ?? $to);
    if ($action === 'clients') {
        $report = ClienticsClientsImporter::run($from, $to, $dryRun);
    } elseif ($action === 'appointments') {
        $report = ClienticsAppointmentsImporter::run($from, $to, $dryRun);
    } elseif ($action === 'users') {
        $report = ClienticsUsersImporter::run($dryRun);
    } elseif ($action === 'services') {
        $report = ClienticsServicesImporter::run($dryRun);
    } elseif ($action === 'waiting_list') {
        $report = ClienticsWaitingListImporter::run($dryRun);
    } elseif ($action === 'outbox') {
        $report = ClienticsOutboxPusher::flush(50);
    } elseif ($action === 'inventory') {
        $report = ClienticsApiInventory::probe(true);
    } else {
        $error = 'unknown action';
    }
    if ($report !== null) {
        if (!empty($report['ok'])) {
            $message = t('clientics.import.done');
        } else {
            $error = t('clientics.import.failed') . ': ' . (string)($report['error'] ?? $report['message'] ?? '');
        }
    }
    if ($wantsJson) {
        if ($error !== '' && ($report === null || empty($report['ok']))) {
            AgentHttp::error($error !== '' ? $error : 'import failed', 400, ['report' => $report]);
            return;
        }
        AgentHttp::emit(['ok' => true, 'action' => $action, 'report' => $report]);
        return;
    }
}

$lastClients = ClienticsSyncStateRepository::get('import_clients');
$lastAppts = ClienticsSyncStateRepository::get('import_appointments');
if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'api_ready' => ClienticsConfig::apiReady(),
        'pull_enabled' => ClienticsConfig::pullEnabled(),
        'push_enabled' => ClienticsConfig::pushEnabled(),
        'appointment_sot' => ClienticsConfig::appointmentSot(),
        'from' => $from,
        'to' => $to,
        'last_clients' => $lastClients,
        'last_appointments' => $lastAppts,
        'report' => $report,
        'waiting_list_available' => ClienticsApiInventory::waitingListAvailable(),
        'medical_cards_available' => ClienticsApiInventory::medicalCardsAvailable(),
    ]);
    return;
}

View::render('import', [
    'title' => t('clientics.import.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('clientics.menu.status'), 'href' => '/clientics'],
        ['label' => t('clientics.menu.import'), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'report' => $report,
    'last' => $lastClients,
    'last_appointments' => $lastAppts,
    'from' => $from,
    'to' => $to,
    'api_ready' => ClienticsConfig::apiReady(),
    'pull_enabled' => ClienticsConfig::pullEnabled(),
    'push_enabled' => ClienticsConfig::pushEnabled(),
    'appointment_sot' => ClienticsConfig::appointmentSot(),
    'waiting_list_available' => ClienticsApiInventory::waitingListAvailable(),
    'medical_cards_available' => ClienticsApiInventory::medicalCardsAvailable(),
], 'layout', 'clientics');
