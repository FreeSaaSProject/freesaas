<?php
declare(strict_types=1);

VideoWorkerAuth::assertToken();

$workerKey = trim((string)($_GET['worker_key'] ?? $_POST['worker_key'] ?? 'default'));
$ttl = max(60, (int)($_GET['ttl'] ?? $_POST['ttl'] ?? 300));

$job = VideoJobsRepository::claim($workerKey, $ttl);

header('Content-Type: application/json; charset=utf-8');
if ($job === null) {
    echo json_encode(['ok' => true, 'job' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
// Attach downloadable media URL when payload has image_rel
if (!empty($payload['image_rel'])) {
    $payload['image_url'] = '/video/worker/file?rel=' . rawurlencode((string)$payload['image_rel'])
        . '&token=' . rawurlencode(VideoWorkerAuth::configuredToken());
}

echo json_encode([
    'ok' => true,
    'job' => [
        'id' => (int)$job['ID'],
        'type' => (string)$job['TYPE'],
        'payload' => $payload,
        'claimed_until' => (int)$job['CLAIMED_UNTIL'],
    ],
], JSON_UNESCAPED_UNICODE);
