<?php
declare(strict_types=1);

/**
 * POST /audio/asr/asr-dia-result — optional pull-style save (push path uses AsrDiaPushDispatcher).
 * Body JSON or form: job_id + speakers/segments/cue_speakers/transcription/meta.
 */
require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_diarize/lib/AsrDiaRunsRepository.php');
require_once base_path('modules/audio_diarize/lib/SpeakerService.php');

AsrWorkerAuth::assertToken();

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}
$jobId = trim((string)($data['job_id'] ?? ''));
header('Content-Type: application/json; charset=utf-8');
if ($jobId === '' || !AsrDiaRunsRepository::available()) {
    echo json_encode(['ok' => false, 'error' => 'bad_job'], JSON_UNESCAPED_UNICODE);
    return;
}
$ok = AsrDiaRunsRepository::saveResult($jobId, $data);
echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
