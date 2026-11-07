<?php
declare(strict_types=1);

/** LongJob: YClients staff import (clients = page crumbs; staff = one shot). */
final class YclientsLongJob
{
    public const TYPE_IMPORT = 'yclients.import';

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function onStart(array $job, array $opts): array
    {
        unset($opts);
        $kind = (string)($job['kind'] ?? 'clients');
        if (!in_array($kind, ['clients', 'staff'], true)) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'unknown action; use clients|staff';
            $job['message'] = $job['error'];
            return $job;
        }
        $job['kind'] = $kind;
        $job['dry_run'] = !empty($job['dry_run']);
        $job['page'] = 1;
        $job['stats'] = [
            'ok' => false,
            'dry_run' => $job['dry_run'],
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'linked' => 0,
            'skipped' => 0,
            'pages' => 0,
            'sample_errors' => [],
        ];
        $job['progress'] = 5;
        $job['progress_overall'] = 5;
        $job['message'] = $kind === 'staff'
            ? t('yclients.longjob.start_staff')
            : t('yclients.longjob.start_clients');
        LongJob::appendLog($job, $job['message']);

        if (!YclientsConfig::pullEnabled()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'pull disabled';
            $job['message'] = $job['error'];
            return $job;
        }
        if (!YclientsConfig::apiReady()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'api config incomplete';
            $job['message'] = $job['error'];
            return $job;
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function tick(array $job): array
    {
        $kind = (string)($job['kind'] ?? 'clients');
        $dry = !empty($job['dry_run']);

        if ($kind === 'staff') {
            $report = YclientsStaffImporter::run($dry);
            if (empty($report['ok'])) {
                $job['status'] = LongJob::STATUS_ERROR;
                $job['error'] = (string)($report['error'] ?? t('yclients.import.failed'));
                $job['message'] = $job['error'];
                return $job;
            }
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('yclients.import.done');
            $job['result'] = $report;
            LongJob::appendLog($job, t('yclients.longjob.staff_done', [
                'n' => (string)(int)($report['fetched'] ?? 0),
            ]));
            return $job;
        }

        $stats = is_array($job['stats'] ?? null) ? $job['stats'] : [];
        $page = max(1, (int)($job['page'] ?? 1));
        $chunk = YclientsClientsImporter::importPage($page, $dry, $stats);
        $job['stats'] = $stats;
        if (!$chunk['ok']) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = (string)($chunk['error'] ?? $stats['error'] ?? t('yclients.import.failed'));
            $job['message'] = $job['error'];
            return $job;
        }
        LongJob::appendLog($job, t('yclients.longjob.clients_page', [
            'page' => (string)$page,
            'fetched' => (string)(int)($stats['fetched'] ?? 0),
        ]));
        if (!empty($chunk['done'])) {
            $stats['ok'] = true;
            YclientsSyncStateRepository::mark('import_clients', true, '', $stats, 'page=' . $page);
            $job['stats'] = $stats;
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('yclients.import.done');
            $job['result'] = $stats;
            LongJob::appendLog($job, t('yclients.longjob.clients_done', [
                'n' => (string)(int)($stats['fetched'] ?? 0),
            ]));
            return $job;
        }
        $job['page'] = (int)$chunk['next_page'];
        // Soft progress: pages climb; unknown total → asymptotic toward 90%.
        $pages = max(1, (int)($stats['pages'] ?? 1));
        $job['progress'] = min(90, 10 + (int)floor(80 * (1 - 1 / ($pages + 1))));
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('yclients.longjob.clients_page', [
            'page' => (string)$page,
            'fetched' => (string)(int)($stats['fetched'] ?? 0),
        ]);
        return $job;
    }
}
