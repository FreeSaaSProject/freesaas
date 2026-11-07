<?php
declare(strict_types=1);

ClienticsJobsAuth::assertWorkerToken();

$report = ClienticsApiInventory::probe(true);

AgentHttp::emit([
    'ok' => !empty($report['ok']),
    'inventory' => $report,
]);
