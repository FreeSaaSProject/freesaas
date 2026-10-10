<?php
declare(strict_types=1);

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once __DIR__ . '/lib/AsrClaimLock.php';
require_once __DIR__ . '/lib/AsrService.php';
require_once __DIR__ . '/lib/JobLock.php';
require_once __DIR__ . '/lib/JobRunner.php';
require_once __DIR__ . '/lib/IngestRunner.php';
require_once __DIR__ . '/lib/StemRunner.php';
require_once __DIR__ . '/lib/CollectRunner.php';

/**
 * Cabin audio analytics workers (ingest / base_records ASR / stem / collect).
 * Shared worker token + messenger voice queue live in module `asr`.
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
    // NEVER wipe instances / collections / SCORE for the whole DB here.
    // A single key/dict/type/trash save used to call markAllForReparse() and
    // zero every SCORE + DELETE all instances — that bit production.
    // Safe reaction: refresh stem lemma cache only. Day/record reparse stays
    // explicit via calendar Re-® or /audio/jobs/stem?reset=1&day=…|record=….
    StemRunner::invalidateCaches();
    return is_array($payload) ? $payload : [];
});

Health::register('audio_pipeline.stem_cache', 'audio_pipeline', static function (): array {
    $dir = base_path('var/audio_stem_cache');
    if (!is_dir($dir)) {
        return [
            'status' => 'degraded',
            'message' => 'var/audio_stem_cache missing (will be created on first stem run)',
        ];
    }
    if (!is_writable($dir)) {
        return ['status' => 'fail', 'message' => 'var/audio_stem_cache not writable'];
    }
    return ['status' => 'ok', 'message' => 'stem cache dir writable'];
}, 'Stem cache directory');
