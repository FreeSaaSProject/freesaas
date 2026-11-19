<?php
declare(strict_types=1);

/**
 * GET /audio/asr/asr-dia-status — profiles pack for asr_dia worker (same vectors as diarize).
 */
require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_diarize/lib/SpeakerService.php');

AsrWorkerAuth::assertToken();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'asr_dia_enabled' => SpeakerService::diarizeEnabled(),
    'profiles_ready' => SpeakerService::hasReadyProfiles(),
    'match_cue' => SpeakerService::matchCueThreshold(),
    'match_margin' => SpeakerService::matchMarginThreshold(),
    'profiles' => SpeakerService::diarizeEnabled() ? SpeakerService::profilesPayloadForWorker() : [],
], JSON_UNESCAPED_UNICODE);
