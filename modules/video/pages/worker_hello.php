<?php
declare(strict_types=1);

VideoWorkerAuth::assertToken();

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$workerKey = trim((string)($data['worker_key'] ?? $data['worker'] ?? 'default'));
$hostname = trim((string)($data['hostname'] ?? gethostname() ?: ''));
$meta = $data['meta'] ?? [];
if (!is_array($meta)) {
    $meta = [];
}

VideoWorkersRepository::heartbeat($workerKey, $hostname, $meta);

$cameras = [];
foreach (VideoCamerasRepository::listEnabled() as $cam) {
    $cameras[] = [
        'id' => (int)$cam['ID'],
        'name' => (string)$cam['NAME'],
        'rtsp_url' => (string)$cam['RTSP_URL'],
        'mode' => (string)$cam['MODE'],
        'model_id' => (int)$cam['MODEL_ID'],
        'conf_threshold' => (float)$cam['CONF_THRESHOLD'],
        'iou_threshold' => (float)$cam['IOU_THRESHOLD'],
        'interval_sec' => (int)$cam['INTERVAL_SEC'],
        'settings' => json_decode((string)($cam['SETTINGS_JSON'] ?? ''), true) ?: [],
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'server_time' => time(),
    'cameras' => $cameras,
    'retention' => [
        'retention_days' => (int)Config::get('video', 'retention_days', 7),
        'max_storage_mb' => (int)Config::get('video', 'max_storage_mb', 2048),
        'purge_mode' => (string)Config::get('video', 'purge_mode', 'default'),
    ],
], JSON_UNESCAPED_UNICODE);
