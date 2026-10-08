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
        if (!empty($report['ok'])) {
            $message = t('clientics.import.done');
        } else {
            $error = t('clientics.import.failed') . ': ' . (string)($report['error'] ?? '');
        }
    } else {
        $error = 'unknown action';
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

$last = ClienticsSyncStateRepository::get('import_clients');
if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'api_ready' => ClienticsConfig::apiReady(),
        'pull_enabled' => ClienticsConfig::pullEnabled(),
        'from' => $from,
        'to' => $to,
        'last' => $last,
        'report' => $report,
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
    'last' => $last,
    'from' => $from,
    'to' => $to,
    'api_ready' => ClienticsConfig::apiReady(),
    'pull_enabled' => ClienticsConfig::pullEnabled(),
], 'layout', 'clientics');
