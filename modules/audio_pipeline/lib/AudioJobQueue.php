<?php
declare(strict_types=1);

/**
 * Lightweight work flags for audio_pipeline cron drain.
 * HTTP /audio/jobs/{ingest,stem} only enqueue; Cron (every ~5 min + nightly) processes batches.
 */
final class AudioJobQueue
{
    public static function requestIngest(bool $importBrokenDate = false): array
    {
        $prev = self::read('ingest');
        $payload = [
            'requested_at' => time(),
            'importBrokenDate' => $importBrokenDate,
        ];
        self::write('ingest', $payload);
        return [
            'ok' => true,
            'queued' => true,
            'already' => $prev !== null,
            'message' => 'ingest_queued',
            'hint' => 'cron_within_5_min',
        ];
    }

    /**
     * @param array{day?:string,admin?:int,record?:int,collect?:bool,publish?:bool,limit?:int} $opts
     */
    public static function requestStem(array $opts = []): array
    {
        $day = trim((string)($opts['day'] ?? ''));
        if ($day === '') {
            $day = date('Y-m-d');
        }
        $prev = self::read('stem');
        $payload = [
            'requested_at' => time(),
            'day' => $day,
            'admin' => max(0, (int)($opts['admin'] ?? 0)),
            'record' => max(0, (int)($opts['record'] ?? 0)),
            'collect' => !isset($opts['collect']) || $opts['collect'] !== false,
            'publish' => !isset($opts['publish']) || $opts['publish'] !== false,
            'limit' => max(0, (int)($opts['limit'] ?? 0)),
        ];
        // Keep earliest day if already queued for an older unfinished day? Prefer merge: keep existing day if set.
        if (is_array($prev) && trim((string)($prev['day'] ?? '')) !== '') {
            $payload['day'] = (string)$prev['day'];
            $payload['admin'] = max(0, (int)($prev['admin'] ?? $payload['admin']));
            $payload['record'] = max(0, (int)($prev['record'] ?? $payload['record']));
        }
        self::write('stem', $payload);
        return [
            'ok' => true,
            'queued' => true,
            'already' => $prev !== null,
            'message' => 'stem_queued',
            'day' => $payload['day'],
            'hint' => 'cron_within_5_min',
        ];
    }

    public static function clear(string $name): void
    {
        $path = self::path($name);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function isRequested(string $name): bool
    {
        return self::read($name) !== null;
    }

    /**
     * One ingest batch. Keeps flag if inbox still has files; clears when empty.
     * $force: nightly — run even without flag if inbox non-empty.
     *
     * @return array{ok:bool,processed:int,skipped:int,message:string,extra?:array}
     */
    public static function drainIngest(bool $force = false): array
    {
        $req = self::read('ingest');
        $importBroken = is_array($req) && !empty($req['importBrokenDate']);

        if ($req === null && !$force) {
            if (!self::inboxHasFiles()) {
                return ['ok' => true, 'processed' => 0, 'skipped' => 1, 'message' => 'nothing_to_do'];
            }
            // Inbox dirty without flag — treat as work (uploader wrote files, forgot ping).
        } elseif ($req === null && $force && !self::inboxHasFiles()) {
            return ['ok' => true, 'processed' => 0, 'skipped' => 1, 'message' => 'nothing_to_do'];
        }

        $lock = JobLock::tryAcquire('ingest');
        if ($lock === null) {
            // Keep request for next tick
            if ($req === null) {
                self::requestIngest($importBroken);
            }
            return ['ok' => true, 'skipped' => 1, 'processed' => 0, 'message' => 'blocked_run'];
        }
        try {
            $out = IngestRunner::run($importBroken);
            $hasMore = self::inboxHasFiles();
            if ($hasMore) {
                self::requestIngest($importBroken);
                $out['message'] = (string)($out['message'] ?? 'ingest_done') . ':more_pending';
                $out['extra'] = array_merge(
                    is_array($out['extra'] ?? null) ? $out['extra'] : [],
                    ['requeued' => true]
                );
            } else {
                self::clear('ingest');
            }
            return $out;
        } finally {
            $lock->release();
        }
    }

    /**
     * One stem batch (+ collect/publish when day pending hits 0).
     *
     * @return array{ok:bool,processed:int,skipped:int,message:string,extra?:array}
     */
    public static function drainStem(bool $force = false): array
    {
        $req = self::read('stem');
        $day = is_array($req) ? trim((string)($req['day'] ?? '')) : '';
        if ($day === '') {
            $day = date('Y-m-d');
        }
        $admin = is_array($req) ? max(0, (int)($req['admin'] ?? 0)) : 0;
        $record = is_array($req) ? max(0, (int)($req['record'] ?? 0)) : 0;
        $collect = !is_array($req) || !isset($req['collect']) || $req['collect'] !== false;
        $publish = !is_array($req) || !isset($req['publish']) || $req['publish'] !== false;
        $limit = is_array($req) ? max(0, (int)($req['limit'] ?? 0)) : 0;

        $pending = StemRunner::countPendingForDay($day);
        if ($req === null && !$force && $pending < 1) {
            // Also check lookback unparsed without day scope for force=false
            return ['ok' => true, 'processed' => 0, 'skipped' => 1, 'message' => 'nothing_to_do'];
        }
        if ($req === null && $force && $pending < 1) {
            // Nightly: try today; if empty, try yesterday
            $yday = date('Y-m-d', time() - 86400);
            $yPending = StemRunner::countPendingForDay($yday);
            if ($yPending > 0) {
                $day = $yday;
                $pending = $yPending;
            } else {
                return ['ok' => true, 'processed' => 0, 'skipped' => 1, 'message' => 'nothing_to_do'];
            }
        }

        $lock = JobLock::tryAcquire('stem');
        if ($lock === null) {
            if ($req === null) {
                self::requestStem(['day' => $day, 'admin' => $admin, 'record' => $record, 'collect' => $collect, 'publish' => $publish, 'limit' => $limit]);
            }
            return ['ok' => true, 'skipped' => 1, 'processed' => 0, 'message' => 'blocked_run'];
        }

        try {
            $range = StemRunner::parseDayOption($day);
            if ($range === null) {
                self::clear('stem');
                return ['ok' => false, 'processed' => 0, 'skipped' => 0, 'message' => 'invalid_day'];
            }

            $batchLimit = $limit > 0 ? $limit : JobRunner::batchSize();
            $stem = StemRunner::run([
                'day' => $day,
                'admin' => $admin,
                'record' => $record,
                'unparsed_only' => true,
                'collect' => false,
                'limit' => $batchLimit,
                'time_budget_sec' => JobRunner::timeBudgetSec(),
            ]);
            if (($stem['message'] ?? '') === 'stem_libs_unavailable') {
                return [
                    'ok' => false,
                    'processed' => (int)($stem['processed'] ?? 0),
                    'skipped' => (int)($stem['skipped'] ?? 0),
                    'message' => 'stem_libs_unavailable',
                    'extra' => ['day' => $day],
                ];
            }

            $pendingAfter = StemRunner::countPendingForDay($day);
            $extra = [
                'day' => $day,
                'pending' => $pendingAfter,
                'stem' => $stem['extra'] ?? [],
            ];

            if ($pendingAfter > 0) {
                self::requestStem([
                    'day' => $day,
                    'admin' => $admin,
                    'record' => $record,
                    'collect' => $collect,
                    'publish' => $publish,
                    'limit' => $limit,
                ]);
                return [
                    'ok' => true,
                    'processed' => (int)($stem['processed'] ?? 0),
                    'skipped' => (int)($stem['skipped'] ?? 0),
                    'message' => 'stem_batch_requeued',
                    'extra' => array_merge($extra, ['requeued' => true]),
                ];
            }

            // Day drained — collect + publish once, then clear.
            if ($collect) {
                $admins = StemRunner::adminIdsForDay($day);
                if ($admin > 0) {
                    $admins = [$admin];
                }
                $coll = CollectRunner::run(false, $admins, [
                    'start' => $range['start'],
                    'end' => $range['end'],
                    'time_budget_sec' => 90,
                ]);
                $extra['collect'] = [
                    'ok' => !empty($coll['ok']),
                    'message' => (string)($coll['message'] ?? ''),
                    'processed' => (int)($coll['processed'] ?? 0),
                    'collections' => (int)(($coll['extra']['collections'] ?? 0)),
                    'admins' => count($admins),
                ];
                $disabledN = StemRunner::disableOutsideCoverageAfterCollect(
                    (int)$range['start'],
                    (int)$range['end'],
                    $admin > 0 ? $admin : 0
                );
                if ($disabledN > 0) {
                    $extra['disabled_records'] = $disabledN;
                }
                if ($publish && is_file(base_path('modules/audio_reports/lib/CalendarHooks.php'))) {
                    require_once base_path('modules/audio_reports/lib/CalendarHooks.php');
                    if (class_exists('CalendarHooks', false)) {
                        $hook = CalendarHooks::emitReparseAfter([
                            'date' => $day,
                            'day_start' => (int)$range['start'],
                            'day_end' => (int)$range['end'],
                            'admin_ids' => $admins,
                        ]);
                        if (isset($hook['staff_reports']) && is_array($hook['staff_reports'])) {
                            $extra['staff_reports'] = $hook['staff_reports']['stats']
                                ?? $hook['staff_reports'];
                        }
                    }
                }
            }

            self::clear('stem');
            return [
                'ok' => true,
                'processed' => (int)($stem['processed'] ?? 0),
                'skipped' => (int)($stem['skipped'] ?? 0),
                'message' => 'stem_day_done',
                'extra' => $extra,
            ];
        } finally {
            $lock->release();
        }
    }

    public static function inboxHasFiles(): bool
    {
        require_once base_path('modules/audio/lib/AudioSettingsRepository.php');
        require_once base_path('modules/audio/lib/FileStorage.php');
        if (!class_exists('FileStorage', false) || !class_exists('AudioSettingsRepository', false)) {
            return false;
        }
        try {
            $cfg = AudioSettingsRepository::storageConfig();
            $storage = FileStorage::fromSettings();
            $inbox = '/' . trim($cfg['audio_inbox'], '/') . '/';
            $files = array_merge(
                $storage->glob($inbox, '*.mp3'),
                $storage->glob($inbox, '*.m4a')
            );
            return $files !== [];
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    private static function read(string $name): ?array
    {
        $path = self::path($name);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $data */
    private static function write(string $name, array $data): void
    {
        $dir = base_path('var/audio_jobs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = self::path($name);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $tmp = $path . '.tmp_' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        @rename($tmp, $path);
    }

    private static function path(string $name): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) ?: 'job';
        return base_path('var/audio_jobs/queue_' . $safe . '.json');
    }
}
