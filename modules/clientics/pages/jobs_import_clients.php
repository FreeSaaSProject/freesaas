<?php
declare(strict_types=1);

ClienticsJobsAuth::assertToken();

if (!ClienticsConfig::pullEnabled()) {
    AgentHttp::error('pull disabled', 503);
    return;
}

$dryRun = !empty($_GET['dry_run']) || !empty($_POST['dry_run']);
$from = (string)($_GET['from'] ?? $_POST['from'] ?? date('Y-m-d', strtotime('-7 days')));
$to = (string)($_GET['to'] ?? $_POST['to'] ?? date('Y-m-d'));
$report = ClienticsClientsImporter::run($from, $to, $dryRun);
if (empty($report['ok'])) {
    AgentHttp::error((string)($report['error'] ?? 'import failed'), 502, ['report' => $report]);
    return;
}

AgentHttp::emit([
    'ok' => true,
    'job' => 'import_clients',
    'report' => $report,
]);
