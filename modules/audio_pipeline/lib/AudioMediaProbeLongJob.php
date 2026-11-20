<?php
declare(strict_types=1);

/**
 * LongJob: ffprobe PLAYTIME + VOLUME for day / record ids (no ASR).
 */
final class AudioMediaProbeLongJob
{
    public const TYPE = 'audio_pipeline.media_probe';

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function onStart(array $job, array $opts): array
    {
        unset($opts);
        require_once base_path('modules/audio_pipeline/lib/MediaProbeRunsRepository.php');
        require_once base_path('modules/audio_pipeline/lib/MediaProbePushDispatcher.php');

        if (!MediaProbeRunsRepository::available()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'runs_unavailable';
            $job['message'] = t('audio_pipeline.media_probe.err.unavailable');
            return $job;
        }

        $force = !isset($job['force']) || !empty($job['force']);
        $ids = self::parseIds($job);
        $day = trim((string)($job['day'] ?? ''));
        $admin = isset($job['admin']) ? (int)$job['admin'] : 0;
        if ($ids === [] && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $ids = MediaProbeRunsRepository::recordIdsForDay($day, $admin > 0 ? $admin : null);
        }
        if ($ids === []) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'no_ids';
            $job['message'] = t('audio_pipeline.media_probe.err.empty');
            return $job;
        }

        $runIds = MediaProbeRunsRepository::enqueueRecords($ids, $force);
        MediaProbePushDispatcher::syncAll();
        // Also collect/dispatch via ASR sync so /results are drained.
        if (class_exists('AsrPushDispatcher', false)) {
            AsrPushDispatcher::syncAll();
            MediaProbePushDispatcher::syncAll();
        }

        $job['record_ids'] = $ids;
        $job['run_ids'] = $runIds;
        $job['force'] = $force ? 1 : 0;
        $job['total'] = count($runIds);
        $job['done'] = 0;
        $job['progress'] = 0;
        $job['progress_overall'] = 0;
        $job['message'] = t('audio_pipeline.media_probe.queued', ['n' => (string)count($runIds)]);
        LongJob::appendLog($job, $job['message']);
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function tick(array $job): array
    {
        require_once base_path('modules/audio_pipeline/lib/MediaProbeRunsRepository.php');
        require_once base_path('modules/audio_pipeline/lib/MediaProbePushDispatcher.php');

        if (class_exists('AsrPushDispatcher', false)) {
            AsrPushDispatcher::syncAll();
        }
        MediaProbePushDispatcher::syncAll();

        $runIds = [];
        foreach ((array)($job['run_ids'] ?? []) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $runIds[] = $id;
            }
        }
        $total = max(1, (int)($job['total'] ?? count($runIds)));
        $counts = MediaProbeRunsRepository::countsForRunIds($runIds);
        $done = (int)$counts['done'] + (int)$counts['failed'];
        $job['done'] = $done;
        $job['progress'] = (int)round(100.0 * $done / $total);
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('audio_pipeline.media_probe.progress', [
            'done' => (string)$done,
            'total' => (string)$total,
            'failed' => (string)$counts['failed'],
        ]);

        if ($done >= $total) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['message'] = t('audio_pipeline.media_probe.done', [
                'done' => (string)$counts['done'],
                'failed' => (string)$counts['failed'],
            ]);
            LongJob::appendLog($job, $job['message']);
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return list<int>
     */
    private static function parseIds(array $job): array
    {
        $ids = [];
        foreach ((array)($job['record_ids'] ?? []) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $raw = trim((string)($job['ids'] ?? ''));
        if ($raw !== '') {
            foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $p) {
                $id = (int)$p;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        return array_values($ids);
    }
}
