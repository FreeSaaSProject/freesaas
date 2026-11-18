<?php
declare(strict_types=1);

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_pipeline/lib/AsrService.php');
if (
    ModuleManager::isEnabled('audio_diarize')
    && is_file(base_path('modules/audio_diarize/lib/SpeakerService.php'))
) {
    require_once base_path('modules/audio_diarize/lib/SpeakerEmbedding.php');
    require_once base_path('modules/audio_diarize/lib/SpeakerService.php');
}

AsrWorkerAuth::assertToken();

try {
    $result = trim(urldecode((string)($_POST['result'] ?? '')));
    $fileName = trim(urldecode((string)($_POST['file_name'] ?? '')));
    $playtime = isset($_POST['PLAYTIME']) ? trim(urldecode((string)$_POST['PLAYTIME'])) : null;
    $volume = isset($_POST['VOLUME']) ? trim(urldecode((string)$_POST['VOLUME'])) : null;
    $speakersPayload = null;
    if (class_exists('SpeakerService', false)) {
        $speakersPayload = SpeakerService::payloadFromRequest($_POST);
    }

    $out = AsrService::saveResult($fileName, $result, $playtime, $volume, $speakersPayload);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out['ok'], JSON_UNESCAPED_UNICODE);
    echo "\n" . $out['diag'];
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(false, JSON_UNESCAPED_UNICODE);
    echo "\nerror=" . $e->getMessage();
    error_log('[audio/asr/result] ' . $e->getMessage());
}
