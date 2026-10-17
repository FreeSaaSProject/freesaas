<?php
declare(strict_types=1);

/**
 * Inbox → base_records. Safe to call via GET (uploader / host cron):
 *   GET /audio/jobs/ingest?token=WORKER_TOKEN
 * Header X-Audio-Token also works. Optional: importBrokenDate=1.
 * Platform backup: Cron job audio_pipeline.ingest daily at 02:00 via /cron/tick.
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
    error_log('[audio/jobs/ingest] ' . $e->getMessage());
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
