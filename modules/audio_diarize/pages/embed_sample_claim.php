<?php
declare(strict_types=1);

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_diarize/lib/SpeakerSamplesRepository.php');

AsrWorkerAuth::assertToken();

$adminId = isset($_GET['admin']) ? (int)$_GET['admin'] : 0;
$job = SpeakerSamplesRepository::claimPendingEmbed($adminId);
header('Content-Type: application/json; charset=utf-8');
if ($job === null) {
    echo 'None';
    return;
}
echo json_encode($job, JSON_UNESCAPED_UNICODE);
