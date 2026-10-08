<?php
declare(strict_types=1);

YclientsJobsAuth::assertToken();

if (!YclientsConfig::pullEnabled()) {
    AgentHttp::error('pull disabled', 503);
    return;
}

$dryRun = !empty($_GET['dry_run']) || !empty($_POST['dry_run']);
$report = YclientsClientsImporter::run($dryRun);
if (empty($report['ok'])) {
    AgentHttp::error((string)($report['error'] ?? 'import failed'), 502, ['report' => $report]);
    return;
}

AgentHttp::emit([
    'ok' => true,
    'job' => 'import_clients',
    'report' => $report,
]);
