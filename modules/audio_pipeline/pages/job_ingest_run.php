<?php
declare(strict_types=1);

/**
 * Sync inbox → base_records (blocking). Prefer enqueue URL for uploaders:
 *   GET /audio/jobs/ingest?token=WORKER_TOKEN  → 202 queued
 * Sync processor (token):
 *   GET|POST /audio/jobs/ingest/run?token=WORKER_TOKEN
 * Optional: importBrokenDate=1.
 */

require_once base_path('modules/asr/lib/AsrWorkerAuth.php');
require_once base_path('modules/audio_pipeline/lib/JobLock.php');
require_once base_path('modules/audio_pipeline/lib/JobRunner.php');
require_once base_path('modules/audio_pipeline/lib/IngestRunner.php');

AsrWorkerAuth::assertToken();
$lock = JobLock::tryAcquire('ingest');
if ($lock === null) {
    JobRunner::blocked('ingest');
    return;
}
try {
    $req = array_merge($_GET ?? [], $_POST ?? []);
    $importBroken = !empty($req['importBrokenDate']) && (string)$req['importBrokenDate'] !== '0';
    JobRunner::jsonResponse(IngestRunner::run($importBroken));
} catch (Throwable $e) {
    error_log('[audio/jobs/ingest/run] ' . $e->getMessage());
    JobRunner::jsonResponse([
        'ok' => false,
        'processed' => 0,
        'skipped' => 0,
        'message' => 'ingest_error',
        'extra' => ['error' => $e->getMessage()],
    ], 500);
} finally {
    $lock->release();
}
