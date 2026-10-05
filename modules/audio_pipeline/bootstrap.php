<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/AsrWorkerAuth.php';
require_once __DIR__ . '/lib/AsrClaimLock.php';
require_once __DIR__ . '/lib/AsrService.php';
require_once __DIR__ . '/lib/JobLock.php';
require_once __DIR__ . '/lib/JobRunner.php';
require_once __DIR__ . '/lib/IngestRunner.php';
require_once __DIR__ . '/lib/StemRunner.php';
require_once __DIR__ . '/lib/CollectRunner.php';

/**
 * Thin ASR handshake + HTTP job runners. Whisper Python stays external.
 * Shared hosting: cron → curl -H "X-Audio-Token: …" /audio/jobs/{ingest|stem|collect}
 */

$asrRoutes = [
    '/audio/asr/claim' => 'claim.php',
    '/audio/asr/result' => 'result.php',
    '/audio/asr/chunk-claim' => 'chunk_claim.php',
    '/audio/asr/chunk-result' => 'chunk_result.php',
];

foreach ($asrRoutes as $path => $file) {
    ModuleManager::registerRoute($path, [
        'module' => 'audio_pipeline',
        'methods' => ['GET', 'POST'],
        'public' => true,
        'handler' => static function () use ($file): void {
            require base_path('modules/audio_pipeline/pages/' . $file);
        },
    ]);
}

$jobRoutes = [
    '/audio/jobs/ingest' => 'job_ingest.php',
    '/audio/jobs/stem' => 'job_stem.php',
    '/audio/jobs/collect' => 'job_collect.php',
];

foreach ($jobRoutes as $path => $file) {
    ModuleManager::registerRoute($path, [
        'module' => 'audio_pipeline',
        'methods' => ['GET', 'POST'],
        'public' => true,
        'handler' => static function () use ($file): void {
            require base_path('modules/audio_pipeline/pages/' . $file);
        },
    ]);
}

EventBus::on('audio_dicts.changed', static function ($payload) {
    StemRunner::invalidateCaches();
    return is_array($payload) ? $payload : [];
});
