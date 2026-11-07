<?php
declare(strict_types=1);

/** LongJob: inbox → records ingest (one IngestRunner batch per tick). */
final class AudioIngestLongJob
{
    public const TYPE = 'audio.ingest';

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function onStart(array $job, array $opts): array
    {
        unset($opts);
        if (!ModuleManager::isEnabled('audio_pipeline')) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'audio_pipeline disabled';
            $job['message'] = $job['error'];
            return $job;
        }
        require_once base_path('modules/audio_pipeline/lib/JobLock.php');
        require_once base_path('modules/audio_pipeline/lib/JobRunner.php');
        require_once base_path('modules/audio_pipeline/lib/IngestRunner.php');

        $pending = InboxFiles::count();
        $job['pending_start'] = $pending;
        $job['processed'] = 0;
        $job['skipped'] = 0;
        $job['import_broken'] = !empty($job['import_broken']);
        $job['progress'] = 0;
        $job['progress_overall'] = 0;
        $job['message'] = t('audio.inbox.ingest_pending', ['n' => (string)$pending]);
        LongJob::appendLog($job, $job['message']);
        if ($pending < 1) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('audio.inbox.ingest_empty');
            $job['result'] = ['processed' => 0, 'skipped' => 0, 'pending' => 0];
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function tick(array $job): array
    {
        require_once base_path('modules/audio_pipeline/lib/JobLock.php');
        require_once base_path('modules/audio_pipeline/lib/JobRunner.php');
        require_once base_path('modules/audio_pipeline/lib/IngestRunner.php');

        $lock = JobLock::tryAcquire('ingest');
        if ($lock === null) {
            LongJob::appendLog($job, t('audio.inbox.ingest_busy'));
            $job['message'] = t('audio.inbox.ingest_busy');
            // Stay running — next tick retries (cron/worker may hold lock briefly).
            return $job;
        }
        try {
            $result = IngestRunner::run(!empty($job['import_broken']));
        } finally {
            $lock->release();
        }
        $processed = (int)($result['processed'] ?? 0);
        $skipped = (int)($result['skipped'] ?? 0);
        $job['processed'] = (int)($job['processed'] ?? 0) + $processed;
        $job['skipped'] = (int)($job['skipped'] ?? 0) + $skipped;
        LongJob::appendLog($job, t('audio.inbox.ingest_stats', [
            'processed' => (string)$processed,
            'skipped' => (string)$skipped,
            'message' => (string)($result['message'] ?? ''),
        ]));
        $notes = $result['extra']['notes'] ?? [];
        if (is_array($notes)) {
            foreach (array_slice($notes, 0, 5) as $note) {
                LongJob::appendLog($job, (string)$note);
            }
        }
        $pending = InboxFiles::count();
        $start = max(1, (int)($job['pending_start'] ?? 1));
        $done = max(0, $start - $pending);
        $frac = min(1.0, (float)$done / (float)$start);
        $job['progress'] = (int)floor($frac * 100);
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('audio.inbox.ingest_continue_hint', ['n' => (string)$pending]);
        if ($pending < 1 || ($processed + $skipped) < 1) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('audio.inbox.ingest_done');
            $job['result'] = [
                'processed' => (int)$job['processed'],
                'skipped' => (int)$job['skipped'],
                'pending' => $pending,
            ];
            LongJob::appendLog($job, $job['message']);
        }
        return $job;
    }
}
