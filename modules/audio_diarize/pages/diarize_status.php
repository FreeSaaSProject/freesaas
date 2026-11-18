<?php
declare(strict_types=1);

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_diarize/lib/SpeakerService.php');

AsrWorkerAuth::assertToken();

header('Content-Type: application/json; charset=utf-8');
$enabled = SpeakerService::diarizeEnabled();
$ready = $enabled && SpeakerService::hasReadyProfiles();
$payload = [
    'ok' => true,
    'diarize_enabled' => $enabled,
    'profiles_ready' => $ready,
    'requires_profiles' => SpeakerService::diarizeRequiresProfiles(),
    'match_cue' => SpeakerService::matchCueThreshold(),
    'match_margin' => SpeakerService::matchMarginThreshold(),
    'match_soft' => SpeakerService::matchCueThreshold(),
];
// Edge worker needs vectors for cue→profile labeling (avoid S0 mega-cluster).
if ($ready) {
    $payload['profiles'] = SpeakerService::profilesPayloadForWorker();
}
echo json_encode($payload, JSON_UNESCAPED_UNICODE);
