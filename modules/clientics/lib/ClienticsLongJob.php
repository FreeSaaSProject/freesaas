<?php
declare(strict_types=1);

/** LongJob: Clientics staff import (clients/appointments = day+page crumbs; rest = one-shot / outbox batches). */
final class ClienticsLongJob
{
    public const TYPE_IMPORT = 'clientics.import';

    private const PAGED_KINDS = ['clients', 'appointments'];

    private const ONESHOT_KINDS = ['users', 'services', 'waiting_list', 'inventory'];

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function onStart(array $job, array $opts): array
    {
        unset($opts);
        $kind = trim((string)($job['kind'] ?? 'clients'));
        $allowed = array_merge(self::PAGED_KINDS, self::ONESHOT_KINDS, ['outbox']);
        if (!in_array($kind, $allowed, true)) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'unknown kind';
            $job['message'] = $job['error'];
            return $job;
        }
        $job['kind'] = $kind;
        $job['dry_run'] = !empty($job['dry_run']);
        $from = trim((string)($job['from'] ?? date('Y-m-d', strtotime('-7 days'))));
        $to = trim((string)($job['to'] ?? date('Y-m-d')));
        $job['from'] = $from;
        $job['to'] = $to;
        $job['day'] = $from;
        $job['offset'] = 0;
        $job['stats'] = self::emptyStats($kind, $job['dry_run'], $from, $to);
        $job['progress'] = 5;
        $job['progress_overall'] = 5;
        $startKeys = [
            'clients' => 'clientics.longjob.start_clients',
            'appointments' => 'clientics.longjob.start_appointments',
            'users' => 'clientics.longjob.start_users',
            'services' => 'clientics.longjob.start_services',
            'waiting_list' => 'clientics.longjob.start_waiting_list',
            'inventory' => 'clientics.longjob.start_inventory',
            'outbox' => 'clientics.longjob.start_outbox',
        ];
        $job['message'] = t($startKeys[$kind] ?? 'clientics.import.title');
        LongJob::appendLog($job, $job['message']);

        if ($kind === 'outbox') {
            if (!ClienticsConfig::apiReady()) {
                $job['status'] = LongJob::STATUS_ERROR;
                $job['error'] = 'api config incomplete';
                $job['message'] = $job['error'];
                return $job;
            }
            return $job;
        }
        if ($kind === 'inventory') {
            if (!ClienticsConfig::apiReady()) {
                $job['status'] = LongJob::STATUS_ERROR;
                $job['error'] = 'api config incomplete';
                $job['message'] = $job['error'];
                return $job;
            }
            return $job;
        }
        if (!ClienticsConfig::pullEnabled()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'pull disabled';
            $job['message'] = $job['error'];
            return $job;
        }
        if (!ClienticsConfig::apiReady()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = 'api config incomplete';
            $job['message'] = $job['error'];
            return $job;
        }
        if (in_array($kind, self::PAGED_KINDS, true)) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $job['status'] = LongJob::STATUS_ERROR;
                $job['error'] = 'from/to must be YYYY-MM-DD';
                $job['message'] = $job['error'];
                return $job;
            }
            if ($from > $to) {
                $job['status'] = LongJob::STATUS_ERROR;
                $job['error'] = 'from > to';
                $job['message'] = $job['error'];
                return $job;
            }
            if ($kind === 'appointments') {
                if (!class_exists('ModuleManager', false) || !ModuleManager::isEnabled('calendar')
                    || !class_exists('EventsRepository', false) || !Database::tableExists('base_events')) {
                    $job['status'] = LongJob::STATUS_ERROR;
                    $job['error'] = 'calendar module / base_events required';
                    $job['message'] = $job['error'];
                    return $job;
                }
            }
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

        if ($kind === 'outbox') {
            return self::tickOutbox($job);
        }

        if ($kind === 'inventory') {
            $report = ClienticsApiInventory::probe(true);
            if (empty($report['ok'])) {
                $job['status'] = LongJob::STATUS_ERROR;
                $job['error'] = (string)($report['error'] ?? t('clientics.import.failed'));
                $job['message'] = $job['error'];
                return $job;
            }
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('clientics.import.done');
            $job['result'] = $report;
            LongJob::appendLog($job, t('clientics.longjob.inventory_done'));
            return $job;
        }

        if (in_array($kind, self::ONESHOT_KINDS, true)) {
            $report = self::runOneshot($kind, $dry);
            if (empty($report['ok'])) {
                $job['status'] = LongJob::STATUS_ERROR;
                $job['error'] = (string)($report['error'] ?? t('clientics.import.failed'));
                $job['message'] = $job['error'];
                return $job;
            }
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('clientics.import.done');
            $job['result'] = $report;
            LongJob::appendLog($job, t('clientics.longjob.oneshot_done', ['kind' => $kind]));
            return $job;
        }

        if (in_array($kind, self::PAGED_KINDS, true)) {
            return self::tickPaged($job, $kind, $dry);
        }

        $job['status'] = LongJob::STATUS_ERROR;
        $job['error'] = 'unknown kind';
        $job['message'] = $job['error'];
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function tickOutbox(array $job): array
    {
        $report = ClienticsOutboxPusher::flush(50);
        $acc = is_array($job['result'] ?? null) ? $job['result'] : [
            'ok' => true,
            'processed' => 0,
            'errors' => 0,
        ];
        $acc['processed'] = (int)($acc['processed'] ?? 0) + (int)($report['processed'] ?? 0);
        $acc['errors'] = (int)($acc['errors'] ?? 0) + (int)($report['errors'] ?? 0);
        $acc['ok'] = empty($report['ok']) ? false : (bool)($acc['ok'] ?? true);
        if (isset($report['message'])) {
            $acc['message'] = (string)$report['message'];
        }
        $job['result'] = $acc;

        if (empty($report['ok']) && (int)($report['processed'] ?? 0) === 0) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = (string)($report['message'] ?? 'outbox flush failed');
            $job['message'] = $job['error'];
            return $job;
        }

        $batch = (int)($report['processed'] ?? 0);
        LongJob::appendLog($job, t('clientics.longjob.outbox_batch', ['n' => (string)$batch]));

        if ($batch < 1) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('clientics.import.done');
            LongJob::appendLog($job, t('clientics.longjob.outbox_done', [
                'n' => (string)(int)($acc['processed'] ?? 0),
            ]));
            return $job;
        }

        $pending = ClienticsOutboxRepository::pendingCount();
        $job['progress'] = $pending > 0 ? min(95, 10 + (int)floor(80 * ($acc['processed'] / ($acc['processed'] + $pending + 1)))) : 90;
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('clientics.longjob.outbox_batch', ['n' => (string)$batch]);
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function tickPaged(array $job, string $kind, bool $dry): array
    {
        $stats = is_array($job['stats'] ?? null) ? $job['stats'] : [];
        $day = (string)($job['day'] ?? $job['from'] ?? date('Y-m-d'));
        $offset = (int)($job['offset'] ?? 0);
        $to = (string)($job['to'] ?? $day);
        $stats['to'] = $to;

        if ($kind === 'clients') {
            $chunk = ClienticsClientsImporter::importPage($day, $offset, $dry, $stats);
            $markJob = 'import_clients';
        } else {
            $chunk = ClienticsAppointmentsImporter::importPage($day, $offset, $dry, $stats);
            $markJob = 'import_appointments';
        }
        $job['stats'] = $stats;

        if (!$chunk['ok']) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = (string)($chunk['error'] ?? $stats['error'] ?? t('clientics.import.failed'));
            $job['message'] = $job['error'];
            return $job;
        }

        LongJob::appendLog($job, t('clientics.longjob.page', [
            'day' => $day,
            'offset' => (string)$offset,
            'fetched' => (string)(int)($stats['fetched'] ?? 0),
        ]));

        if (!empty($chunk['done'])) {
            $stats['ok'] = true;
            $job['stats'] = $stats;
            ClienticsSyncStateRepository::mark($markJob, true, '', $stats, $to);
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('clientics.import.done');
            $job['result'] = $stats;
            LongJob::appendLog($job, t('clientics.longjob.paged_done', [
                'n' => (string)(int)($stats['fetched'] ?? 0),
            ]));
            return $job;
        }

        $job['day'] = (string)$chunk['day'];
        $job['offset'] = (int)$chunk['offset'];
        $pages = max(1, (int)($stats['pages'] ?? 1));
        $job['progress'] = min(90, 10 + (int)floor(80 * (1 - 1 / ($pages + 1))));
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('clientics.longjob.page', [
            'day' => $day,
            'offset' => (string)$offset,
            'fetched' => (string)(int)($stats['fetched'] ?? 0),
        ]);
        return $job;
    }

    /**
     * @return array<string,mixed>
     */
    private static function runOneshot(string $kind, bool $dry): array
    {
        if ($kind === 'users') {
            return ClienticsUsersImporter::run($dry);
        }
        if ($kind === 'services') {
            return ClienticsServicesImporter::run($dry);
        }
        if ($kind === 'waiting_list') {
            return ClienticsWaitingListImporter::run($dry);
        }
        return ['ok' => false, 'error' => 'unknown kind'];
    }

    /**
     * @return array<string,mixed>
     */
    private static function emptyStats(string $kind, bool $dryRun, string $from, string $to): array
    {
        if ($kind === 'clients') {
            return [
                'ok' => false,
                'dry_run' => $dryRun,
                'from' => $from,
                'to' => $to,
                'days' => 0,
                'pages' => 0,
                'fetched' => 0,
                'created' => 0,
                'updated' => 0,
                'linked' => 0,
                'skipped' => 0,
                'seen_ext' => [],
                'days_seen' => [],
                'sample_errors' => [],
            ];
        }
        if ($kind === 'appointments') {
            return [
                'ok' => false,
                'dry_run' => $dryRun,
                'from' => $from,
                'to' => $to,
                'days' => 0,
                'pages' => 0,
                'fetched' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'seen' => [],
                'days_seen' => [],
                'sample_errors' => [],
            ];
        }
        return [];
    }
}
