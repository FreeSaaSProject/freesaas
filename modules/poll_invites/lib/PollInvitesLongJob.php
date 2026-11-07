<?php
declare(strict_types=1);

/** LongJob handlers for poll invite bulk deletes. */
final class PollInvitesLongJob
{
    public const TYPE_DELETE_UNFILLED = 'poll_invites.delete_unfilled';
    public const CHUNK = 500;
    /** Use LongJob when more than this many unfilled stubs match. */
    public const ASYNC_THRESHOLD = 200;

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function onStart(array $job, array $opts): array
    {
        $filters = is_array($job['filters'] ?? null) ? $job['filters'] : [];
        $total = PollInvitesRepository::countUnfilledMatching($filters);
        $job['total'] = $total;
        $job['deleted'] = 0;
        $job['chunk'] = (int)($opts['chunk'] ?? self::CHUNK);
        $job['progress'] = 0;
        $job['progress_overall'] = 0;
        LongJob::appendLog($job, t('poll_invites.longjob.delete_start', ['n' => (string)$total]));
        if ($total <= 0) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['result'] = ['deleted' => 0];
            LongJob::appendLog($job, t('poll_invites.longjob.delete_done', ['n' => '0']));
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function tick(array $job): array
    {
        $filters = is_array($job['filters'] ?? null) ? $job['filters'] : [];
        $chunk = max(50, (int)($job['chunk'] ?? self::CHUNK));
        $n = PollInvitesRepository::deleteUnfilledMatchingChunk($filters, $chunk);
        $job['deleted'] = (int)($job['deleted'] ?? 0) + $n;
        $total = max(1, (int)($job['total'] ?? 1));
        $frac = min(1.0, (float)$job['deleted'] / (float)$total);
        $job['progress'] = (int)floor($frac * 100);
        $job['progress_overall'] = $job['progress'];
        LongJob::appendLog($job, t('poll_invites.longjob.delete_chunk', [
            'n' => (string)$n,
            'done' => (string)$job['deleted'],
            'total' => (string)$total,
        ]));
        if ($n < $chunk) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['result'] = ['deleted' => (int)$job['deleted']];
            LongJob::appendLog($job, t('poll_invites.longjob.delete_done', [
                'n' => (string)$job['deleted'],
            ]));
        }
        return $job;
    }
}
