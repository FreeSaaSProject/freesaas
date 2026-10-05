<?php
declare(strict_types=1);

require_once base_path('modules/audio_pipeline/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_pipeline/lib/AsrService.php');

AsrWorkerAuth::assertToken();
header('Content-Type: text/plain; charset=utf-8');
echo AsrService::claimChunk();
