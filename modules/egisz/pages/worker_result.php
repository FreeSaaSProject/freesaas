<?php
declare(strict_types=1);

EgiszWorkerAuth::assertToken();

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

$job = EgiszWorkerJobsRepository::find($jobId);
if ($job === null) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'job not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$error = isset($data['error']) ? trim((string)$data['error']) : null;
if ($error === '') {
    $error = null;
}
$result = isset($data['result']) && is_array($data['result']) ? $data['result'] : null;
if ($result === null && isset($data['ok']) && empty($data['ok']) && $error === null) {
    $error = 'worker reported failure';
}

EgiszWorkerJobsRepository::complete($jobId, $result, $error);
EgiszWorkerDispatch::applyResult($job, $result, $error);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'job_id' => $jobId], JSON_UNESCAPED_UNICODE);
