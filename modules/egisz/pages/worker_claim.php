<?php
declare(strict_types=1);

EgiszWorkerAuth::assertToken();

$workerKey = trim((string)($_GET['worker_key'] ?? $_POST['worker_key'] ?? 'default'));
$ttl = max(60, (int)($_GET['ttl'] ?? $_POST['ttl'] ?? 300));

$job = EgiszWorkerDispatch::enabled() ? EgiszWorkerJobsRepository::claim($workerKey, $ttl) : null;

header('Content-Type: application/json; charset=utf-8');
if ($job === null) {
    echo json_encode(['ok' => true, 'job' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];

echo json_encode([
    'ok' => true,
    'job' => [
        'id' => (int)$job['ID'],
        'type' => (string)$job['TYPE'],
        'outbox_id' => (int)($job['OUTBOX_ID'] ?? 0),
        'payload' => $payload,
        'claimed_until' => (int)$job['CLAIMED_UNTIL'],
    ],
], JSON_UNESCAPED_UNICODE);
