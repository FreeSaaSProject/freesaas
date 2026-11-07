<?php
declare(strict_types=1);

ClienticsJobsAuth::assertWorkerToken();

$dryRun = !empty($_POST['dry_run']) || !empty($_GET['dry_run']);
$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'all');

$reports = [];
if ($action === 'all' || $action === 'users') {
    $reports['users'] = ClienticsUsersImporter::run($dryRun);
}
if ($action === 'all' || $action === 'services') {
    $reports['services'] = ClienticsServicesImporter::run($dryRun);
}
if ($action === 'all' || $action === 'waiting_list') {
    $reports['waiting_list'] = ClienticsWaitingListImporter::run($dryRun);
}

$ok = true;
foreach ($reports as $r) {
    if (empty($r['ok'])) {
        $ok = false;
        break;
    }
}

AgentHttp::emit([
    'ok' => $ok,
    'reports' => $reports,
]);
