<?php
declare(strict_types=1);

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_diarize/lib/AsrService.php');
require_once base_path('modules/audio_diarize/lib/SpeakerEmbedding.php');
require_once base_path('modules/audio_diarize/lib/SpeakerService.php');

AsrWorkerAuth::assertToken();

$day = isset($_GET['day']) ? trim((string)$_GET['day']) : null;
$adminId = isset($_GET['admin']) ? (int)$_GET['admin'] : 0;
$lookback = isset($_GET['lookback']) ? (int)$_GET['lookback'] : 3;

$job = AsrService::claimDiarize($day, $adminId, $lookback);
header('Content-Type: application/json; charset=utf-8');
if ($job === null) {
    echo 'None';
    return;
}
echo json_encode($job, JSON_UNESCAPED_UNICODE);
