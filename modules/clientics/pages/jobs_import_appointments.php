<?php
declare(strict_types=1);

ClienticsJobsAuth::assertWorkerToken();

$from = (string)($_POST['from'] ?? $_GET['from'] ?? date('Y-m-d', strtotime('-1 day')));
$to = (string)($_POST['to'] ?? $_GET['to'] ?? date('Y-m-d'));
$dryRun = !empty($_POST['dry_run']) || !empty($_GET['dry_run']);

$report = ClienticsAppointmentsImporter::run($from, $to, $dryRun);

AgentHttp::emit([
    'ok' => !empty($report['ok']),
    'report' => $report,
]);
