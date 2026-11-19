<?php
declare(strict_types=1);

/**
 * GET /audio/asr/asr-dia-status — profiles pack for asr_dia worker.
 * Optional record_id= → shift-scoped pack for that cabin file (preferred).
 * Without record_id → empty profiles (avoid matching against the whole catalog).
 */
require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_diarize/lib/SpeakerService.php');

AsrWorkerAuth::assertToken();

$recordId = (int)($_GET['record_id'] ?? 0);
$profiles = [];
if (SpeakerService::diarizeEnabled()) {
    $profiles = $recordId > 0
        ? SpeakerService::profilesPayloadForRecord($recordId)
        : [];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'asr_dia_enabled' => SpeakerService::diarizeEnabled(),
    'profiles_ready' => SpeakerService::hasReadyProfiles(),
    'match_cue' => SpeakerService::matchCueThreshold(),
    'match_margin' => SpeakerService::matchMarginThreshold(),
    'record_id' => $recordId,
    'profiles' => $profiles,
], JSON_UNESCAPED_UNICODE);
