<?php
declare(strict_types=1);

require_once base_path('modules/audio_pipeline/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_pipeline/lib/AsrService.php');

AsrWorkerAuth::assertToken();

$result = (string)($_POST['result'] ?? '');
$fileName = (string)($_POST['file_name'] ?? '');
$out = AsrService::saveChunkResult($fileName, $result);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => $out['ok'], 'diag' => $out['diag']], JSON_UNESCAPED_UNICODE);
