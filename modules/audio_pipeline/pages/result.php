<?php
declare(strict_types=1);

require_once base_path('modules/audio_pipeline/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_pipeline/lib/AsrService.php');

AsrWorkerAuth::assertToken();

$result = trim(urldecode((string)($_POST['result'] ?? '')));
$fileName = trim(urldecode((string)($_POST['file_name'] ?? '')));
$playtime = isset($_POST['PLAYTIME']) ? trim(urldecode((string)$_POST['PLAYTIME'])) : null;
$volume = isset($_POST['VOLUME']) ? trim(urldecode((string)$_POST['VOLUME'])) : null;

$out = AsrService::saveResult($fileName, $result, $playtime, $volume);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($out['ok'], JSON_UNESCAPED_UNICODE);
echo "\n" . $out['diag'];
