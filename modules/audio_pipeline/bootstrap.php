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
    $entity = is_array($payload) ? (string)($payload['entity'] ?? '') : '';
    // Keys / dicts / types / trashes change lemma matching → full reparse backlog.
    if (in_array($entity, ['keys', 'dicts', 'types', 'trashes'], true)) {
        StemRunner::markAllForReparse();
    }
    // Script graph change → rebuild collections from existing instances.
    if ($entity === 'scripts' && class_exists('CollectRunner', false)) {
        // Deferred: cron /audio/jobs/collect?full=1; avoid long request on save.
        // Mark via collections truncate so next collect rebuilds everyone.
        if (Database::tableExists('base_records_collections_datas')) {
            Database::pdo()->exec('TRUNCATE TABLE base_records_collections_datas');
        }
        if (Database::tableExists('base_records_collections')) {
            Database::pdo()->exec('TRUNCATE TABLE base_records_collections');
        }
    }
    return is_array($payload) ? $payload : [];
});

Health::register('audio_pipeline.worker_token', 'audio_pipeline', static function (): array {
    $token = trim((string)Config::get('audio', 'worker_token', ''));
    if ($token === '' || $token === 'CHANGE_ME') {
        return [
            'status' => 'degraded',
            'message' => 'audio.worker_token missing or still CHANGE_ME — ASR/jobs auth will reject',
        ];
    }
    return ['status' => 'ok', 'message' => 'worker_token configured'];
}, 'ASR/job worker token');

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
