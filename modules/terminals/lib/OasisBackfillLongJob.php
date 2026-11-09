<?php
declare(strict_types=1);

/** LongJob: chunked IS_OASIS 0→2 backfill (schema migrate must stay under UI tick budget). */
final class OasisBackfillLongJob
{
    public const TYPE = 'terminals.oasis_backfill';

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function onStart(array $job, array $opts): array
    {
        unset($opts);
        $remaining = FullstatsRepository::oasisBackfillRemaining();
        $job['stats'] = [
            'updated' => 0,
            'named' => 0,
            'remaining_start' => $remaining,
        ];
        $job['progress'] = $remaining < 1 ? 100 : 5;
        $job['progress_overall'] = $job['progress'];
        if ($remaining < 1) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['message'] = t('terminals.oasis_backfill.empty');
            $job['result'] = ['updated' => 0, 'remaining' => 0];
            LongJob::appendLog($job, $job['message']);
            return $job;
        }
        $job['message'] = t('terminals.oasis_backfill.start', ['n' => $remaining]);
        LongJob::appendLog($job, $job['message']);
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function tick(array $job): array
    {
        $chunk = 25000;
        $res = FullstatsRepository::backfillOasisNotChunk($chunk);
        $stats = is_array($job['stats'] ?? null) ? $job['stats'] : [];
        $stats['updated'] = (int)($stats['updated'] ?? 0) + (int)$res['updated'];
        $stats['named'] = (int)($stats['named'] ?? 0) + (int)$res['named'];
        $job['stats'] = $stats;
        $remaining = (int)$res['remaining'];
        $start = max(1, (int)($stats['remaining_start'] ?? $remaining));
        $done = max(0, $start - $remaining);
        $job['progress'] = (int)min(99, floor(100.0 * $done / $start));
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('terminals.oasis_backfill.tick', [
            'updated' => (int)$res['updated'],
            'remaining' => $remaining,
        ]);
        LongJob::appendLog($job, $job['message']);
        if (!empty($res['done'])) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('terminals.oasis_backfill.done', [
                'n' => (int)$stats['updated'],
            ]);
            $job['result'] = [
                'updated' => (int)$stats['updated'],
                'named' => (int)$stats['named'],
                'remaining' => 0,
            ];
            LongJob::appendLog($job, $job['message']);
        }
        return $job;
    }
}
