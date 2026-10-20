<?php
declare(strict_types=1);

/**
 * Push latest annotated frame without a full job (live preview path).
 */
VideoWorkerAuth::assertToken();

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$cameraId = (int)($data['camera_id'] ?? 0);
if ($cameraId <= 0 || empty($data['annotated_b64'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'camera_id and annotated_b64 required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cam = VideoCamerasRepository::find($cameraId);
if ($cam === null) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'camera not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$bin = base64_decode((string)$data['annotated_b64'], true);
if ($bin === false) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'bad base64'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ring = max(1, (int)Config::get('video', 'latest_frame_ring', 3));
$rel = VideoStorage::putBytes('live/' . $cameraId, 'live_' . time() . '.jpg', $bin);

$detections = $data['detections'] ?? [];
if (!is_array($detections)) {
    $detections = [];
}
$frameRel = '';
if (!empty($data['frame_b64'])) {
    $rawFrame = base64_decode((string)$data['frame_b64'], true);
    if ($rawFrame !== false) {
        $frameRel = VideoStorage::putBytes('live/' . $cameraId, 'raw_' . time() . '.jpg', $rawFrame);
    }
}

// Trim both annotated and raw rings (otherwise raw_*.jpg orphans grow forever)
$liveSub = 'live/' . $cameraId;
VideoStorage::trimRing($liveSub, 'live_*.jpg', $ring);
VideoStorage::trimRing($liveSub, 'raw_*.jpg', $ring);

VideoCamerasRepository::updateLastFrame($cameraId, $frameRel, $rel, ['detections' => $detections, 'live' => true]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'annotated_rel' => $rel], JSON_UNESCAPED_UNICODE);
