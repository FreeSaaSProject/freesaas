<?php
declare(strict_types=1);

/**
 * Lightweight work flags for audio_pipeline cron drain.
 * HTTP /audio/jobs/{ingest,stem} only enqueue; Cron (every ~5 min + nightly) processes batches.
 *
 * Stem queue holds a list of scopes (day/admin/record). New requests merge/append —
 * they must not replace other pending days/staff.
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
     * not_before: unix ts the scope may start (0 = next cron tick). Merging keeps the earliest,
     * so repeat late results never push an already scheduled day further away.
     *
     * @param array{day?:string,admin?:int,record?:int,collect?:bool,publish?:bool,limit?:int,not_before?:int} $opts
     */
    public static function requestStem(array $opts = []): array
    {
        $job = self::stemJobFromOpts($opts);
        $before = 0;
        $jobs = self::withStemQueueLock(static function () use ($job, &$before): array {
            $prev = self::read('stem');
            $jobs = self::stemJobsFromPayload($prev);
            $before = count($jobs);
            $jobs = self::upsertStemJob($jobs, $job);
            self::persistStemJobs($jobs);
            return $jobs;
        });
        $key = self::stemJobKey($job);
        $notBefore = $job['not_before'];
        foreach ($jobs as $j) {
            if (self::stemJobKey($j) === $key) {
                $notBefore = (int)$j['not_before'];
                break;
            }
        }

        return [
            'ok' => true,
            'queued' => true,
            'already' => $before > 0,
            'merged' => $before > 0 && count($jobs) === $before,
            'message' => 'stem_queued',
            'day' => $job['day'],
            'not_before' => $notBefore,
            'days' => array_values(array_unique(array_map(
                static fn (array $j): string => (string)$j['day'],
                $jobs
            ))),
            'jobs' => count($jobs),
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
     * One stem batch for the first queued scope (+ collect/publish when that day pending hits 0).
     *
     * @return array{ok:bool,processed:int,skipped:int,message:string,extra?:array}
     */
    public static function drainStem(bool $force = false): array
    {
        $sweep = $force ? self::enqueueNightlySweep() : [];
        $req = self::read('stem');
        $allJobs = self::stemJobsFromPayload($req);
        $job = self::firstDueStemJob($allJobs, time());
        $deferred = count($allJobs) - ($job !== null ? 1 : 0);

        $day = '';
        $admin = 0;
        $record = 0;
        $collect = true;
        $publish = true;
        $limit = 0;
        $notBefore = 0;

        if ($job !== null) {
            $day = (string)$job['day'];
            $admin = (int)$job['admin'];
            $record = (int)$job['record'];
            $collect = !empty($job['collect']);
            $publish = !empty($job['publish']);
            $limit = (int)$job['limit'];
            $notBefore = (int)$job['not_before'];
        } else {
            $day = date('Y-m-d');
        }

        $pending = StemRunner::countPendingForDay($day);
        if ($job === null && $pending < 1) {
            $idleExtra = [];
            if ($sweep !== []) {
                $idleExtra['sweep'] = $sweep;
            }
            if ($deferred > 0) {
                $idleExtra['deferred_jobs'] = $deferred;
            }
            return [
                'ok' => true,
                'processed' => 0,
                'skipped' => 1,
                'message' => $deferred > 0 ? 'stem_deferred' : 'nothing_to_do',
                'extra' => $idleExtra,
            ];
        }

        $lock = JobLock::tryAcquire('stem');
        if ($lock === null) {
            if ($job === null) {
                self::requestStem([
                    'day' => $day,
                    'admin' => $admin,
                    'record' => $record,
                    'collect' => $collect,
                    'publish' => $publish,
                    'limit' => $limit,
                ]);
            }
            return ['ok' => true, 'skipped' => 1, 'processed' => 0, 'message' => 'blocked_run'];
        }

        $headJob = self::stemJobFromOpts([
            'day' => $day,
            'admin' => $admin,
            'record' => $record,
            'collect' => $collect,
            'publish' => $publish,
            'limit' => $limit,
            'not_before' => $notBefore,
        ]);
        $headKey = self::stemJobKey($headJob);

        try {
            $range = StemRunner::parseDayOption($day);
            if ($range === null) {
                $remaining = self::withStemQueueLock(static function () use ($headKey): int {
                    $fresh = self::stemJobsFromPayload(self::read('stem'));
                    $fresh = self::dropStemJobByKey($fresh, $headKey);
                    self::persistStemJobs($fresh);
                    return count($fresh);
                });
                return [
                    'ok' => false,
                    'processed' => 0,
                    'skipped' => 0,
                    'message' => 'invalid_day',
                    'extra' => [
                        'day' => $day,
                        'remaining_jobs' => $remaining,
                    ],
                ];
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
                    'extra' => ['day' => $day, 'jobs' => count($allJobs)],
                ];
            }

            $pendingAfter = StemRunner::countPendingForDay($day);
            $extra = [
                'day' => $day,
                'pending' => $pendingAfter,
                'stem' => $stem['extra'] ?? [],
            ];
            if ($sweep !== []) {
                $extra['sweep'] = $sweep;
            }

            if ($pendingAfter > 0) {
                // Keep current scope at head; siblings enqueued during run stay behind it.
                $queued = self::withStemQueueLock(static function () use ($headJob, $headKey): int {
                    $fresh = self::stemJobsFromPayload(self::read('stem'));
                    $fresh = self::upsertStemJobHead($fresh, $headJob, $headKey);
                    self::persistStemJobs($fresh);
                    return count($fresh);
                });
                $extra['jobs_queued'] = $queued;
                return [
                    'ok' => true,
                    'processed' => (int)($stem['processed'] ?? 0),
                    'skipped' => (int)($stem['skipped'] ?? 0),
                    'message' => 'stem_batch_requeued',
                    'extra' => array_merge($extra, ['requeued' => true]),
                ];
            }

            // Day drained — collect + publish once for this scope, then drop it.
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

            $remaining = self::withStemQueueLock(static function () use ($headKey): int {
                $fresh = self::stemJobsFromPayload(self::read('stem'));
                $fresh = self::dropStemJobByKey($fresh, $headKey);
                self::persistStemJobs($fresh);
                return count($fresh);
            });
            $extra['remaining_jobs'] = $remaining;
            $extra['jobs_queued'] = $remaining;

            return [
                'ok' => true,
                'processed' => (int)($stem['processed'] ?? 0),
                'skipped' => (int)($stem['skipped'] ?? 0),
                'message' => $remaining < 1 ? 'stem_day_done' : 'stem_day_done_more_queued',
                'extra' => $extra,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Morning (07:00) safety net: yesterday always (same-day publish was refused while it was today),
     * plus every lookback day that still has transcribed-but-unparsed records. Runs immediately.
     *
     * @return list<string> days enqueued
     */
    private static function enqueueNightlySweep(): array
    {
        $days = [];
        $today = (int)mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        for ($i = StemRunner::LOOKBACK_DAYS; $i >= 1; $i--) {
            $day = date('Y-m-d', (int)mktime(0, 0, 0, (int)date('n', $today), (int)date('j', $today) - $i, (int)date('Y', $today)));
            if ($i === 1 || StemRunner::countPendingForDay($day) > 0) {
                $days[] = $day;
            }
        }
        if (StemRunner::countPendingForDay(date('Y-m-d', $today)) > 0) {
            $days[] = date('Y-m-d', $today);
        }
        foreach ($days as $day) {
            self::requestStem(['day' => $day]);
        }
        return $days;
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

    /**
     * @param array{day?:string,admin?:int,record?:int,collect?:bool,publish?:bool,limit?:int,not_before?:int} $opts
     * @return array{day:string,admin:int,record:int,collect:bool,publish:bool,limit:int,not_before:int}
     */
    private static function stemJobFromOpts(array $opts): array
    {
        $day = trim((string)($opts['day'] ?? ''));
        if ($day === '') {
            $day = date('Y-m-d');
        }
        return [
            'day' => $day,
            'admin' => max(0, (int)($opts['admin'] ?? 0)),
            'record' => max(0, (int)($opts['record'] ?? 0)),
            'collect' => !isset($opts['collect']) || $opts['collect'] !== false,
            'publish' => !isset($opts['publish']) || $opts['publish'] !== false,
            'limit' => max(0, (int)($opts['limit'] ?? 0)),
            'not_before' => max(0, (int)($opts['not_before'] ?? 0)),
        ];
    }

    /**
     * First scope whose not_before has passed, in queue order.
     *
     * @param list<array{day:string,admin:int,record:int,collect:bool,publish:bool,limit:int,not_before:int}> $jobs
     * @return array{day:string,admin:int,record:int,collect:bool,publish:bool,limit:int,not_before:int}|null
     */
    private static function firstDueStemJob(array $jobs, int $now): ?array
    {
        foreach ($jobs as $job) {
            if ((int)$job['not_before'] <= $now) {
                return $job;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return list<array{day:string,admin:int,record:int,collect:bool,publish:bool,limit:int}>
     */
    private static function stemJobsFromPayload(?array $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }
        if (isset($payload['jobs']) && is_array($payload['jobs'])) {
            $out = [];
            foreach ($payload['jobs'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $day = trim((string)($row['day'] ?? ''));
                if ($day === '') {
                    continue;
                }
                $out[] = self::stemJobFromOpts($row);
            }
            return $out;
        }
        // Legacy single-scope file (pre multi-job).
        $day = trim((string)($payload['day'] ?? ''));
        if ($day === '') {
            return [];
        }
        return [self::stemJobFromOpts($payload)];
    }

    /**
     * @param list<array{day:string,admin:int,record:int,collect:bool,publish:bool,limit:int}> $jobs
     * @param array{day:string,admin:int,record:int,collect:bool,publish:bool,limit:int} $job
     * @return list<array{day:string,admin:int,record:int,collect:bool,publish:bool,limit:int}>
     */
    private static function upsertStemJob(array $jobs, array $job): array
    {
        $key = self::stemJobKey($job);
        foreach ($jobs as $i => $existing) {
            if (self::stemJobKey($existing) === $key) {
                $jobs[$i] = [
                    'day' => $job['day'],
                    'admin' => $job['admin'],
                    'record' => $job['record'],
                    // Broader flags win: keep work if either request asked for it.
                    'collect' => !empty($existing['collect']) || !empty($job['collect']),
                    'publish' => !empty($existing['publish']) || !empty($job['publish']),
                    'limit' => self::mergeStemLimit((int)$existing['limit'], (int)$job['limit']),
                    // Earliest start wins: immediate pings run now, repeat late results don't postpone.
                    'not_before' => min((int)$existing['not_before'], (int)$job['not_before']),
                ];
                return $jobs;
            }
        }
        $jobs[] = $job;
        return $jobs;
    }

    /** @param array{day:string,admin:int,record:int,collect:bool,publish:bool,limit:int} $job */
    private static function stemJobKey(array $job): string
    {
        return $job['day'] . '|' . $job['admin'] . '|' . $job['record'];
    }

    /**
     * Drop the finished scope by key (first match). Keeps siblings enqueued during the run.
     *
     * @param list<array{day:string,admin:int,record:int,collect:bool,publish:bool,limit:int}> $jobs
     * @return list<array{day:string,admin:int,record:int,collect:bool,publish:bool,limit:int}>
     */
    private static function dropStemJobByKey(array $jobs, string $key): array
    {
        foreach ($jobs as $i => $existing) {
            if (self::stemJobKey($existing) === $key) {
                array_splice($jobs, $i, 1);
                break;
            }
        }
        return array_values($jobs);
    }

    /**
     * Ensure head scope stays first after a partial batch; merge flags with any concurrent upsert.
     *
     * @param list<array{day:string,admin:int,record:int,collect:bool,publish:bool,limit:int}> $jobs
     * @param array{day:string,admin:int,record:int,collect:bool,publish:bool,limit:int} $headJob
     * @return list<array{day:string,admin:int,record:int,collect:bool,publish:bool,limit:int}>
     */
    private static function upsertStemJobHead(array $jobs, array $headJob, string $headKey): array
    {
        $jobs = self::upsertStemJob($jobs, $headJob);
        // Move matching key to index 0 without dropping siblings.
        $idx = null;
        foreach ($jobs as $i => $existing) {
            if (self::stemJobKey($existing) === $headKey) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null || $idx === 0) {
            return $jobs;
        }
        $row = $jobs[$idx];
        array_splice($jobs, $idx, 1);
        array_unshift($jobs, $row);
        return array_values($jobs);
    }

    private static function mergeStemLimit(int $a, int $b): int
    {
        // 0 = default batch size (unrestricted vs explicit cap) — keep 0 if either is 0.
        if ($a < 1 || $b < 1) {
            return 0;
        }
        return max($a, $b);
    }

    /**
     * Short exclusive flock around stem-queue RMW (not JobLock — runners hold that for minutes).
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private static function withStemQueueLock(callable $fn)
    {
        $dir = base_path('var/audio_jobs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir . '/queue_stem.lock';
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return $fn();
        }
        flock($fp, LOCK_EX);
        try {
            return $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @param list<array{day:string,admin:int,record:int,collect:bool,publish:bool,limit:int}> $jobs
     */
    private static function persistStemJobs(array $jobs): void
    {
        if ($jobs === []) {
            self::clear('stem');
            return;
        }
        self::write('stem', [
            'requested_at' => time(),
            'jobs' => array_values($jobs),
        ]);
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
