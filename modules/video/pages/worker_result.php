<?php
declare(strict_types=1);

VideoWorkerAuth::assertToken();

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$jobId = (int)($data['job_id'] ?? 0);
if ($jobId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'job_id required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$job = VideoJobsRepository::find($jobId);
if ($job === null) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'job not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$alertId = VideoResultIngest::apply($job, $data);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'job_id' => $jobId, 'alert_id' => $alertId], JSON_UNESCAPED_UNICODE);
