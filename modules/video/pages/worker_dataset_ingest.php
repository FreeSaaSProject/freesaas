<?php
declare(strict_types=1);

VideoWorkerAuth::assertToken();

if (!class_exists(VideoLabelZipIngest::class, false)) {
    if (is_file(base_path('modules/video_label/lib/VideoLabelZipIngest.php'))) {
        require_once base_path('modules/video_label/lib/VideoLabelZipIngest.php');
    }
}
if (!class_exists(VideoLabelZipIngest::class, false) || !class_exists(VideoLabelProjectsRepository::class, false)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'video_label module required'], JSON_UNESCAPED_UNICODE);
    return;
}

$projectId = (int)($_POST['project_id'] ?? 0);
$file = $_FILES['zip'] ?? null;
if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'zip file required'], JSON_UNESCAPED_UNICODE);
    return;
}

$tmp = (string)($file['tmp_name'] ?? '');
$name = (string)($file['name'] ?? 'dataset.zip');
$result = VideoLabelZipIngest::fromUpload($projectId, $tmp, $name);
$code = !empty($result['ok']) ? 200 : 422;
http_response_code($code);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
