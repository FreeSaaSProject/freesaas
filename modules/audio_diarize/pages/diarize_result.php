<?php
declare(strict_types=1);

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_diarize/lib/SpeakerEmbedding.php');
require_once base_path('modules/audio_diarize/lib/SpeakerService.php');
require_once base_path('modules/audio_diarize/lib/DiarizeLease.php');

AsrWorkerAuth::assertToken();

try {
    if (!SpeakerService::diarizeEnabled()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(false, JSON_UNESCAPED_UNICODE);
        echo "\ndiarize_disabled";
        return;
    }
    $fileName = trim(urldecode((string)($_POST['file_name'] ?? '')));
    if ($fileName === '' || !Database::tableExists('base_records')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(false, JSON_UNESCAPED_UNICODE);
        echo "\nmissing_filename";
        return;
    }
    $st = Database::pdo()->prepare('SELECT ID FROM base_records WHERE FILENAME = ? LIMIT 1');
    $st->execute([$fileName]);
    $id = (int)$st->fetchColumn();
    if ($id < 1) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(false, JSON_UNESCAPED_UNICODE);
        echo "\nnot_found";
        return;
    }
    $payload = SpeakerService::payloadFromRequest($_POST);
    $out = SpeakerService::saveForRecord($id, $payload);
    if (!empty($out['ok'])) {
        DiarizeLease::clearRequest($id);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(!empty($out['ok']), JSON_UNESCAPED_UNICODE);
    echo "\n" . ($out['diag'] ?? '');
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(false, JSON_UNESCAPED_UNICODE);
    echo "\nerror=" . $e->getMessage();
    error_log('[audio/asr/diarize-result] ' . $e->getMessage());
}
