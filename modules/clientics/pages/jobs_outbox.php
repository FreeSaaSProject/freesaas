<?php
declare(strict_types=1);

ClienticsJobsAuth::assertWorkerToken();

$limit = max(1, min(100, (int)($_POST['limit'] ?? $_GET['limit'] ?? 25)));
$report = ClienticsOutboxPusher::flush($limit);

AgentHttp::emit([
    'ok' => !empty($report['ok']),
    'report' => $report,
    'pending' => ClienticsOutboxRepository::pendingCount(),
]);
