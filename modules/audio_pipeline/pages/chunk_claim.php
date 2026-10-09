<?php
declare(strict_types=1);

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_pipeline/lib/AsrService.php');

AsrWorkerAuth::assertToken();
$body = AsrService::claimChunk();
if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'claim' => $body]);
    return;
}
header('Content-Type: text/plain; charset=utf-8');
echo $body;
