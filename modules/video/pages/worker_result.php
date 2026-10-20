<?php
declare(strict_types=1);

VideoWorkerAuth::assertToken();

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$jobId = (int)($data['job_id'] ?? 0);
$error = isset($data['error']) ? trim((string)$data['error']) : null;
$result = $data['result'] ?? null;
if (!is_array($result)) {
    $result = [];
}

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

// Optional frame upload (base64) for monitor / on_demand
$frameRel = '';
$annotatedRel = '';
if (!empty($data['frame_b64'])) {
    $bin = base64_decode((string)$data['frame_b64'], true);
    if ($bin !== false) {
        $camId = (int)($result['camera_id'] ?? $job['payload']['camera_id'] ?? 0);
        $frameRel = VideoStorage::putBytes('frames/' . max(0, $camId), 'f_' . time() . '.jpg', $bin);
    }
}
if (!empty($data['annotated_b64'])) {
    $bin = base64_decode((string)$data['annotated_b64'], true);
    if ($bin !== false) {
        $camId = (int)($result['camera_id'] ?? $job['payload']['camera_id'] ?? 0);
        $annotatedRel = VideoStorage::putBytes('frames/' . max(0, $camId), 'a_' . time() . '.jpg', $bin);
    }
}

$type = (string)$job['TYPE'];
$alertId = 0;

if ($type === VideoJobsRepository::TYPE_MONITOR_TICK || $type === VideoJobsRepository::TYPE_CAPTURE_FRAME
    || $type === VideoJobsRepository::TYPE_ON_DEMAND) {
    $cameraId = (int)($result['camera_id'] ?? $job['payload']['camera_id'] ?? 0);
    $detections = $result['detections'] ?? [];
    if (!is_array($detections)) {
        $detections = [];
    }
    $allPaths = array_values(array_filter([$annotatedRel, $frameRel], static fn (string $p): bool => $p !== ''));
    $pathForDetect = $annotatedRel !== '' ? $annotatedRel : $frameRel;
    $extraPaths = [];
    foreach ($allPaths as $p) {
        if ($p !== $pathForDetect) {
            $extraPaths[] = $p;
        }
    }
    $bytes = 0;
    foreach ($allPaths as $p) {
        $bytes += VideoStorage::sizeBytes($p);
    }

    if (!empty($result['alert']) && is_array($result['alert'])) {
        $alertId = VideoAlertsRepository::create(
            $cameraId,
            (string)($result['alert']['kind'] ?? 'absence'),
            (string)($result['alert']['message'] ?? ''),
            $allPaths,
            is_array($result['alert']['meta'] ?? null) ? $result['alert']['meta'] : []
        );
        if (class_exists(EventBus::class, false)) {
            EventBus::emit('video.alert.created', [
                'alert_id' => $alertId,
                'camera_id' => $cameraId,
                'kind' => (string)($result['alert']['kind'] ?? 'absence'),
                'message' => (string)($result['alert']['message'] ?? ''),
                'paths' => $allPaths,
            ]);
        }
    }

    if ($cameraId > 0 && ($pathForDetect !== '' || $detections !== [] || $extraPaths !== [])) {
        VideoDetectionsRepository::insert($cameraId, $pathForDetect, $detections, $alertId, $bytes, $extraPaths);
        VideoCamerasRepository::updateLastFrame($cameraId, $frameRel, $annotatedRel, [
            'detections' => $detections,
            'alert_id' => $alertId,
        ]);
    }

    // Dataset collection mode: copy frame into video_label project if requested
    if (!empty($result['dataset_project_id']) && $frameRel !== '' && class_exists(VideoLabelProjectsRepository::class, false)) {
        VideoLabelProjectsRepository::ingestMonitoringFrame(
            (int)$result['dataset_project_id'],
            VideoStorage::abs($frameRel)
        );
    }
}

if ($type === VideoJobsRepository::TYPE_INFER_IMAGE || $type === VideoJobsRepository::TYPE_INFER_BATCH) {
    if (class_exists(VideoLabelProjectsRepository::class, false) && !empty($result['predictions'])) {
        VideoLabelProjectsRepository::applyPredictions($result);
    }
}

if ($type === VideoJobsRepository::TYPE_TRAIN && empty($error)) {
    $weightsRel = '';
    if (!empty($data['weights_b64']) && !empty($data['weights_name'])) {
        $bin = base64_decode((string)$data['weights_b64'], true);
        if ($bin !== false) {
            $weightsRel = VideoStorage::putBytes('models', (string)$data['weights_name'], $bin);
        }
    } elseif (!empty($result['weights_rel'])) {
        $weightsRel = (string)$result['weights_rel'];
    }
    if ($weightsRel !== '') {
        $name = (string)($result['model_name'] ?? ('trained_' . date('Ymd_His')));
        $classes = $result['classes'] ?? [];
        if (!is_array($classes)) {
            $classes = [];
        }
        $modelId = VideoModelsRepository::save(0, $name, $weightsRel, $classes, (string)($result['notes'] ?? 'train job ' . $jobId));
        $result['model_id'] = $modelId;
        $result['weights_rel'] = $weightsRel;
    }
}

$result['frame_rel'] = $frameRel;
$result['annotated_rel'] = $annotatedRel;
$result['alert_id'] = $alertId;

VideoJobsRepository::complete($jobId, $result, $error);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'job_id' => $jobId, 'alert_id' => $alertId], JSON_UNESCAPED_UNICODE);
