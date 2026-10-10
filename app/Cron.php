<?php
declare(strict_types=1);

/**
 * Platform scheduled-job registry + hard-tick runner (shared hosting).
 *
 * Modules register in bootstrap via Cron::register(). Host crontab hits
 * GET/POST /cron/tick (AgentAuth) or `php bin/freesaas.php cron tick`.
 * Soft-cron on page hits is intentionally not supported.
 */
final class Cron
{
    public const STATUS_OK = 'ok';
    public const STATUS_FAIL = 'fail';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_RUNNING = 'running';

    private const STALE_RUNNING_SEC = 600;
    private const DEFAULT_BUDGET_SEC = 50;
    private const RETENTION_DAYS = 14;
    private const RETENTION_MAX_ROWS = 500;

    /** @var array<string, array{module:string,label:string,every:?int,daily_at:?string,handler:callable}> */
    private static $jobs = [];

    /**
     * @param array{
     *   module:string,
     *   label?:string,
     *   every?:int,
     *   daily_at?:string,
     *   handler:callable
     * } $def
     */
    public static function register(string $id, array $def): void
    {
        $id = trim($id);
        if ($id === '' || !isset($def['handler']) || !is_callable($def['handler'])) {
            return;
        }
        $module = trim((string)($def['module'] ?? ''));
        if ($module === '') {
            return;
        }
        $every = isset($def['every']) ? (int)$def['every'] : null;
        if ($every !== null) {
            $every = max(1, min(10080, $every));
        }
        $dailyAt = isset($def['daily_at']) ? trim((string)$def['daily_at']) : null;
        if ($dailyAt !== null && $dailyAt !== '') {
            if (!preg_match('/^\d{2}:\d{2}$/', $dailyAt)) {
                $dailyAt = null;
            }
        } else {
            $dailyAt = null;
        }
        if ($every === null && $dailyAt === null) {
            $every = 5;
        }
        self::$jobs[$id] = [
            'module' => $module,
            'label' => trim((string)($def['label'] ?? $id)),
            'every' => $every,
            'daily_at' => $dailyAt,
            'handler' => $def['handler'],
        ];
    }

    /**
     * @return array<string, array{module:string,label:string,every:?int,daily_at:?string,handler:callable}>
     */
    public static function registered(): array
    {
        return self::$jobs;
    }

    /**
     * Run due jobs (or a single forced job id).
     *
     * @return array{
     *   ok:bool,
     *   tick_id:string,
     *   blocked?:bool,
     *   ran:list<array<string,mixed>>,
     *   skipped:list<array<string,mixed>>,
     *   message?:string
     * }
     */
    public static function tick(bool $forceAll = false, ?string $onlyJobId = null, ?int $budgetSec = null): array
    {
        $tickId = bin2hex(random_bytes(8));
        $budget = $budgetSec !== null
            ? max(5, min(300, $budgetSec))
            : self::DEFAULT_BUDGET_SEC;
        @set_time_limit($budget + 30);
        @ignore_user_abort(true);

        $lock = self::tryAcquireLock();
        if ($lock === null) {
            return [
                'ok' => false,
                'tick_id' => $tickId,
                'blocked' => true,
                'ran' => [],
                'skipped' => [],
                'message' => 'blocked_run',
            ];
        }

        $ran = [];
        $skipped = [];
        try {
            if (!self::tablesReady()) {
                return [
                    'ok' => false,
                    'tick_id' => $tickId,
                    'ran' => [],
                    'skipped' => [],
                    'message' => 'cron tables missing — run migrate',
                ];
            }

            $deadline = microtime(true) + $budget;
            $now = time();
            $ids = $onlyJobId !== null && $onlyJobId !== ''
                ? [$onlyJobId]
                : array_keys(self::$jobs);

            foreach ($ids as $jobId) {
                if (microtime(true) >= $deadline) {
                    $skipped[] = [
                        'job_id' => $jobId,
                        'status' => self::STATUS_SKIPPED,
                        'message' => 'time_budget',
                    ];
                    continue;
                }
                if (!isset(self::$jobs[$jobId])) {
                    $skipped[] = [
                        'job_id' => $jobId,
                        'status' => self::STATUS_SKIPPED,
                        'message' => 'not_registered',
                    ];
                    continue;
                }
                $def = self::$jobs[$jobId];
                if (!ModuleManager::isEnabled($def['module'])) {
                    $skipped[] = [
                        'job_id' => $jobId,
                        'status' => self::STATUS_SKIPPED,
                        'message' => 'module_disabled',
                    ];
                    continue;
                }
                $state = self::loadState($jobId);
                if ((int)($state['enabled'] ?? 1) !== 1 && $onlyJobId === null) {
                    $skipped[] = [
                        'job_id' => $jobId,
                        'status' => self::STATUS_SKIPPED,
                        'message' => 'disabled',
                    ];
                    continue;
                }
                $force = $forceAll || ($onlyJobId !== null && $onlyJobId === $jobId);
                if (!$force && !self::isDue($def, $state, $now)) {
                    $skipped[] = [
                        'job_id' => $jobId,
                        'status' => self::STATUS_SKIPPED,
                        'message' => 'not_due',
                    ];
                    continue;
                }
                if (!$force && ($state['last_status'] ?? '') === self::STATUS_RUNNING) {
                    $started = (int)($state['last_started_at'] ?? 0);
                    if ($started > 0 && ($now - $started) < self::STALE_RUNNING_SEC) {
                        $skipped[] = [
                            'job_id' => $jobId,
                            'status' => self::STATUS_SKIPPED,
                            'message' => 'still_running',
                        ];
                        continue;
                    }
                }

                $ran[] = self::executeJob($jobId, $def, $tickId);
            }

            self::pruneRuns();
        } finally {
            $lock->release();
        }

        $fail = 0;
        foreach ($ran as $row) {
            if (($row['status'] ?? '') === self::STATUS_FAIL) {
                $fail++;
            }
        }

        return [
            'ok' => $fail === 0,
            'tick_id' => $tickId,
            'ran' => $ran,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function catalogWithState(): array
    {
        $out = [];
        $states = self::tablesReady() ? self::allStates() : [];
        foreach (self::$jobs as $id => $def) {
            $st = $states[$id] ?? null;
            $out[] = [
                'job_id' => $id,
                'module' => $def['module'],
                'label' => $def['label'],
                'every' => $def['every'],
                'daily_at' => $def['daily_at'],
                'module_enabled' => ModuleManager::isEnabled($def['module']),
                'enabled' => $st === null ? 1 : (int)($st['enabled'] ?? 1),
                'last_started_at' => $st !== null ? (int)($st['last_started_at'] ?? 0) : 0,
                'last_finished_at' => $st !== null ? (int)($st['last_finished_at'] ?? 0) : 0,
                'last_status' => $st !== null ? (string)($st['last_status'] ?? '') : '',
                'last_message' => $st !== null ? (string)($st['last_message'] ?? '') : '',
                'last_ms' => $st !== null ? (int)($st['last_ms'] ?? 0) : 0,
                'last_tick_id' => $st !== null ? (string)($st['last_tick_id'] ?? '') : '',
            ];
        }
        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function recentRuns(int $limit = 50): array
    {
        if (!self::tablesReady()) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $st = Database::pdo()->query(
            'SELECT ID AS id, TICK_ID AS tick_id, JOB_ID AS job_id,
                    STARTED_AT AS started_at, FINISHED_AT AS finished_at,
                    STATUS AS status, MESSAGE AS message, MS AS ms,
                    PROCESSED AS processed, SKIPPED AS skipped
             FROM base_cron_runs
             ORDER BY ID DESC
             LIMIT ' . (int)$limit
        );
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public static function setEnabled(string $jobId, bool $enabled): bool
    {
        $jobId = trim($jobId);
        if ($jobId === '' || !isset(self::$jobs[$jobId]) || !self::tablesReady()) {
            return false;
        }
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO base_cron_job_state (JOB_ID, ENABLED)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE ENABLED = VALUES(ENABLED)'
        )->execute([$jobId, $enabled ? 1 : 0]);
        return true;
    }

    public static function tablesReady(): bool
    {
        try {
            return Database::tableExists('base_cron_job_state')
                && Database::tableExists('base_cron_runs');
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Health helper: true when at least one enabled registered job looks stale.
     */
    public static function staleHint(): ?string
    {
        if (!self::tablesReady() || self::$jobs === []) {
            return null;
        }
        $now = time();
        $states = self::allStates();
        $stale = [];
        foreach (self::$jobs as $id => $def) {
            if (!ModuleManager::isEnabled($def['module'])) {
                continue;
            }
            $st = $states[$id] ?? null;
            if ($st !== null && (int)($st['enabled'] ?? 1) !== 1) {
                continue;
            }
            $finished = $st !== null ? (int)($st['last_finished_at'] ?? 0) : 0;
            $interval = $def['every'] !== null ? (int)$def['every'] : 1440;
            $threshold = max(15, $interval) * 60 * 3;
            if ($finished < 1 || ($now - $finished) > $threshold) {
                $stale[] = $id;
            }
        }
        if ($stale === []) {
            return null;
        }
        return 'no recent tick for: ' . implode(', ', array_slice($stale, 0, 5))
            . (count($stale) > 5 ? '…' : '')
            . ' — schedule GET/POST /cron/tick';
    }

    /**
     * @param array{module:string,label:string,every:?int,daily_at:?string,handler:callable} $def
     * @param array<string,mixed>|null $state
     */
    private static function isDue(array $def, ?array $state, int $now): bool
    {
        $finished = $state !== null ? (int)($state['last_finished_at'] ?? 0) : 0;

        if ($def['daily_at'] !== null) {
            $parts = explode(':', $def['daily_at']);
            $h = (int)$parts[0];
            $m = (int)$parts[1];
            $todayAt = (int)mktime($h, $m, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
            if ($now < $todayAt) {
                return false;
            }
            return $finished < $todayAt;
        }

        $every = $def['every'] !== null ? (int)$def['every'] : 5;
        if ($finished < 1) {
            return true;
        }
        return ($now - $finished) >= ($every * 60);
    }

    /**
     * @param array{module:string,label:string,every:?int,daily_at:?string,handler:callable} $def
     * @return array<string,mixed>
     */
    private static function executeJob(string $jobId, array $def, string $tickId): array
    {
        $started = time();
        $t0 = microtime(true);
        self::markRunning($jobId, $started, $tickId);

        $ok = false;
        $processed = 0;
        $skipped = 0;
        $message = '';
        $status = self::STATUS_FAIL;

        try {
            $raw = ($def['handler'])();
            if (!is_array($raw)) {
                $raw = ['ok' => (bool)$raw];
            }
            $norm = self::normalizeResult($raw);
            $ok = $norm['ok'];
            $processed = $norm['processed'];
            $skipped = $norm['skipped'];
            $message = $norm['message'];
            $status = $ok ? self::STATUS_OK : self::STATUS_FAIL;
        } catch (Throwable $e) {
            $ok = false;
            $status = self::STATUS_FAIL;
            $message = $e->getMessage();
        }

        $ms = (int)round((microtime(true) - $t0) * 1000);
        $finished = time();
        self::persistFinish($jobId, $tickId, $started, $finished, $status, $message, $ms, $processed, $skipped);

        return [
            'job_id' => $jobId,
            'status' => $status,
            'ok' => $ok,
            'processed' => $processed,
            'skipped' => $skipped,
            'message' => $message,
            'ms' => $ms,
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{ok:bool,processed:int,skipped:int,message:string}
     */
    private static function normalizeResult(array $raw): array
    {
        $ok = array_key_exists('ok', $raw) ? !empty($raw['ok']) : true;
        $processed = (int)($raw['processed'] ?? $raw['sent'] ?? $raw['written'] ?? $raw['imported'] ?? $raw['created'] ?? 0);
        $skipped = (int)($raw['skipped'] ?? 0);
        $message = trim((string)($raw['message'] ?? $raw['error'] ?? $raw['log'] ?? ''));
        if ($message === '' && isset($raw['ymd'])) {
            $message = 'ymd=' . (string)$raw['ymd']
                . ' checked=' . (int)($raw['checked'] ?? 0)
                . ' sent=' . (int)($raw['sent'] ?? 0);
        }
        if (isset($raw['errors']) && (int)$raw['errors'] > 0 && $ok) {
            // bots drain: errors in batch still "ok" if some sent; keep ok from caller
        }
        return [
            'ok' => $ok,
            'processed' => $processed,
            'skipped' => $skipped,
            'message' => mb_substr($message, 0, 500),
        ];
    }

    private static function markRunning(string $jobId, int $started, string $tickId): void
    {
        Database::pdo()->prepare(
            'INSERT INTO base_cron_job_state
                (JOB_ID, ENABLED, LAST_STARTED_AT, LAST_STATUS, LAST_TICK_ID)
             VALUES (?, 1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                LAST_STARTED_AT = VALUES(LAST_STARTED_AT),
                LAST_STATUS = VALUES(LAST_STATUS),
                LAST_TICK_ID = VALUES(LAST_TICK_ID)'
        )->execute([$jobId, $started, self::STATUS_RUNNING, $tickId]);
    }

    private static function persistFinish(
        string $jobId,
        string $tickId,
        int $started,
        int $finished,
        string $status,
        string $message,
        int $ms,
        int $processed,
        int $skipped
    ): void {
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO base_cron_job_state
                (JOB_ID, ENABLED, LAST_STARTED_AT, LAST_FINISHED_AT, LAST_STATUS, LAST_MESSAGE, LAST_MS, LAST_TICK_ID)
             VALUES (?, 1, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                LAST_STARTED_AT = VALUES(LAST_STARTED_AT),
                LAST_FINISHED_AT = VALUES(LAST_FINISHED_AT),
                LAST_STATUS = VALUES(LAST_STATUS),
                LAST_MESSAGE = VALUES(LAST_MESSAGE),
                LAST_MS = VALUES(LAST_MS),
                LAST_TICK_ID = VALUES(LAST_TICK_ID)'
        )->execute([$jobId, $started, $finished, $status, $message, $ms, $tickId]);

        $pdo->prepare(
            'INSERT INTO base_cron_runs
                (TICK_ID, JOB_ID, STARTED_AT, FINISHED_AT, STATUS, MESSAGE, MS, PROCESSED, SKIPPED)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$tickId, $jobId, $started, $finished, $status, $message, $ms, $processed, $skipped]);
    }

    /** @return array<string,mixed>|null */
    private static function loadState(string $jobId): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT JOB_ID AS job_id, ENABLED AS enabled,
                    LAST_STARTED_AT AS last_started_at, LAST_FINISHED_AT AS last_finished_at,
                    LAST_STATUS AS last_status, LAST_MESSAGE AS last_message,
                    LAST_MS AS last_ms, LAST_TICK_ID AS last_tick_id
             FROM base_cron_job_state WHERE JOB_ID = ? LIMIT 1'
        );
        $st->execute([$jobId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, array<string,mixed>> */
    private static function allStates(): array
    {
        $st = Database::pdo()->query(
            'SELECT JOB_ID AS job_id, ENABLED AS enabled,
                    LAST_STARTED_AT AS last_started_at, LAST_FINISHED_AT AS last_finished_at,
                    LAST_STATUS AS last_status, LAST_MESSAGE AS last_message,
                    LAST_MS AS last_ms, LAST_TICK_ID AS last_tick_id
             FROM base_cron_job_state'
        );
        $out = [];
        foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
            $out[(string)$row['job_id']] = $row;
        }
        return $out;
    }

    private static function pruneRuns(): void
    {
        try {
            $pdo = Database::pdo();
            $cut = time() - (self::RETENTION_DAYS * 86400);
            $pdo->prepare('DELETE FROM base_cron_runs WHERE STARTED_AT < ?')->execute([$cut]);
            $cnt = (int)$pdo->query('SELECT COUNT(*) FROM base_cron_runs')->fetchColumn();
            if ($cnt > self::RETENTION_MAX_ROWS) {
                $drop = $cnt - self::RETENTION_MAX_ROWS;
                $pdo->exec(
                    'DELETE FROM base_cron_runs ORDER BY ID ASC LIMIT ' . (int)$drop
                );
            }
        } catch (Throwable $e) {
            // ignore prune errors
        }
    }

    /** @return object{release:callable}|null */
    private static function tryAcquireLock(): ?object
    {
        $dir = base_path('var/cron');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir . '/tick.lock';
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return null;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return null;
        }
        ftruncate($fp, 0);
        fwrite($fp, (string)getmypid() . ' ' . date('c'));
        fflush($fp);

        return new class ($fp) {
            /** @var resource|null */
            private $fp;

            /** @param resource $fp */
            public function __construct($fp)
            {
                $this->fp = $fp;
            }

            public function release(): void
            {
                if ($this->fp === null) {
                    return;
                }
                flock($this->fp, LOCK_UN);
                fclose($this->fp);
                $this->fp = null;
            }

            public function __destruct()
            {
                $this->release();
            }
        };
    }
}
