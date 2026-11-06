<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';
$report = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $dryRun = !empty($_POST['dry_run']);
    if ($action === 'clients') {
        $report = YclientsClientsImporter::run($dryRun);
        if (!empty($report['ok'])) {
            $message = t('yclients.import.done');
        } else {
            $error = t('yclients.import.failed') . ': ' . (string)($report['error'] ?? '');
        }
    } elseif ($action === 'staff') {
        $report = YclientsStaffImporter::run($dryRun);
        if (!empty($report['ok'])) {
            $message = t('yclients.import.done');
        } else {
            $error = t('yclients.import.failed') . ': ' . (string)($report['error'] ?? '');
        }
    } else {
        $error = 'unknown action; use clients|staff';
    }
    if ($wantsJson) {
        if ($error !== '' && ($report === null || empty($report['ok']))) {
            AgentHttp::error($error !== '' ? $error : 'import failed', 400, ['report' => $report]);
            return;
        }
        AgentHttp::emit(['ok' => true, 'report' => $report]);
        return;
    }
}

$lastClients = YclientsSyncStateRepository::get('import_clients');
$lastStaff = YclientsSyncStateRepository::get('import_staff');
if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'api_ready' => YclientsConfig::apiReady(),
        'pull_enabled' => YclientsConfig::pullEnabled(),
        'last' => $lastClients,
        'last_clients' => $lastClients,
        'last_staff' => $lastStaff,
        'report' => $report,
        'actions' => ['clients', 'staff'],
    ]);
    return;
}

View::render('import', [
    'title' => t('yclients.import.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('yclients.menu.status'), 'href' => '/yclients'],
        ['label' => t('yclients.menu.import'), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'report' => $report,
    'last' => $lastClients,
    'last_staff' => $lastStaff,
    'api_ready' => YclientsConfig::apiReady(),
    'pull_enabled' => YclientsConfig::pullEnabled(),
], 'layout', 'yclients');
