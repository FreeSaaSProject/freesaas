<?php
declare(strict_types=1);

/** LongJob: import 1C ticket visits day-by-day into base_fullstats. */
final class TicketsLongJob
{
    public const TYPE_IMPORT = 'terminals.tickets_import';

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function onStart(array $job, array $opts): array
    {
        unset($opts);
        $from = trim((string)($job['from'] ?? date('Y-m-d')));
        $to = trim((string)($job['to'] ?? $from));
        $job['from'] = $from;
        $job['to'] = $to;
        $job['day'] = $from;
        $job['stats'] = [
            'ok' => true,
            'from' => $from,
            'to' => $to,
            'days_done' => 0,
            'days_total' => 0,
            'imported' => 0,
            'skipped_rows' => 0,
            'failed' => 0,
        ];
        $job['progress'] = 5;
        $job['progress_overall'] = 5;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = t('terminals.fullstats.load.err_dates');
            $job['message'] = $job['error'];
            return $job;
        }
        if ($from > $to) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = t('terminals.fullstats.load.err_range');
            $job['message'] = $job['error'];
            return $job;
        }
        $total = self::dayCount($from, $to);
        if ($total < 1 || $total > 400) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = t('terminals.fullstats.load.err_span');
            $job['message'] = $job['error'];
            return $job;
        }
        $job['stats']['days_total'] = $total;

        if (!TerminalsConfig::syncEnabled()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = t('terminals.fullstats.load.err_sync');
            $job['message'] = $job['error'];
            return $job;
        }
        if (!TerminalsConfig::onecLive()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = TerminalsConfig::onecEnabled()
                ? t('terminals.t.err.onec_config')
                : t('terminals.onec.err.disabled');
            $job['message'] = $job['error'];
            return $job;
        }

        $job['message'] = t('terminals.fullstats.load.start', ['from' => $from, 'to' => $to]);
        LongJob::appendLog($job, $job['message']);
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function tick(array $job): array
    {
        $from = (string)($job['from'] ?? '');
        $to = (string)($job['to'] ?? '');
        $day = (string)($job['day'] ?? $from);
        $stats = is_array($job['stats'] ?? null) ? $job['stats'] : [];

        if ($day === '' || $day > $to) {
            return self::finish($job, $stats);
        }

        $res = TicketsImporter::importDay($day);
        $imported = (int)($res['imported'] ?? 0);
        $skipped = (int)($res['skipped'] ?? 0);
        $stats['imported'] = (int)($stats['imported'] ?? 0) + $imported;
        $stats['skipped_rows'] = (int)($stats['skipped_rows'] ?? 0) + $skipped;
        $stats['days_done'] = (int)($stats['days_done'] ?? 0) + 1;

        if (empty($res['ok'])) {
            $stats['failed'] = (int)($stats['failed'] ?? 0) + 1;
            $stats['ok'] = false;
            LongJob::appendLog($job, t('terminals.fullstats.load.day_fail', [
                'day' => $day,
                'error' => (string)($res['error'] ?? $res['message'] ?? ''),
            ]));
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = (string)($res['error'] ?? t('terminals.fullstats.load.failed'));
            $job['message'] = $job['error'];
            $job['stats'] = $stats;
            $job['result'] = $stats;
            return $job;
        }

        LongJob::appendLog($job, t('terminals.fullstats.load.day_ok', [
            'day' => $day,
            'n' => (string)$imported,
        ]));

        $next = date('Y-m-d', strtotime($day . ' +1 day') ?: time());
        $job['day'] = $next;
        $job['stats'] = $stats;
        $job['message'] = t('terminals.fullstats.load.day_ok', [
            'day' => $day,
            'n' => (string)$imported,
        ]);

        $total = max(1, (int)($stats['days_total'] ?? 1));
        $done = (int)($stats['days_done'] ?? 0);
        $job['progress'] = min(95, 5 + (int)floor(90 * ($done / $total)));
        $job['progress_overall'] = $job['progress'];

        if ($next > $to) {
            return self::finish($job, $stats);
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $stats
     * @return array<string,mixed>
     */
    private static function finish(array $job, array $stats): array
    {
        $job['status'] = LongJob::STATUS_DONE;
        $job['progress'] = 100;
        $job['progress_overall'] = 100;
        $job['stats'] = $stats;
        $job['result'] = $stats;
        $job['message'] = t('terminals.fullstats.load.done', [
            'days' => (string)(int)($stats['days_done'] ?? 0),
            'n' => (string)(int)($stats['imported'] ?? 0),
        ]);
        LongJob::appendLog($job, $job['message']);
        return $job;
    }

    private static function dayCount(string $from, string $to): int
    {
        $a = strtotime($from . ' 12:00:00');
        $b = strtotime($to . ' 12:00:00');
        if ($a === false || $b === false || $b < $a) {
            return 0;
        }
        return (int)floor(($b - $a) / 86400) + 1;
    }
}
