<?php
declare(strict_types=1);

/**
 * Universal long / heavy job orchestration (start → tick → cancel).
 * Domain budgets and handlers live in module register(); this facade is store + protocol only.
 */
final class LongJob
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @param array<string,mixed> $meta
     * @see LongJobRegistry::register
     */
    public static function register(string $type, array $meta): void
    {
        LongJobRegistry::register($type, $meta);
    }

    public static function has(string $type): bool
    {
        return LongJobRegistry::has($type);
    }

    /**
     * Start a registered job. Payload is domain state; opts may include context / tick_seconds.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function start(string $type, array $payload = [], array $opts = []): array
    {
        $meta = LongJobRegistry::get($type);
        self::assertAccess($meta);

        $resolved = LongJobRegistry::resolveStartOpts($type, $opts);
        $dir = LongJobRegistry::storeDirFor($type);
        $id = LongJobStore::newId();
        $now = time();
        $job = array_merge($payload, [
            'id' => $id,
            'type' => $type,
            'status' => self::STATUS_RUNNING,
            'progress' => 0,
            'progress_overall' => 0,
            'progress_detail' => 0,
            'message' => '',
            'error' => null,
            'result' => null,
            'log' => [],
            'created_at' => $now,
            'updated_at' => $now,
            'elapsed' => 0,
            'eta_seconds' => null,
            'user_id' => class_exists('Auth', false) ? Auth::userId() : 0,
            'permission' => (string)($meta['permission'] ?? ''),
            'capability' => (string)($meta['capability'] ?? ''),
        ]);
        if (isset($resolved['tick_seconds']) && (float)$resolved['tick_seconds'] > 0) {
            $job['tick_seconds'] = (float)$resolved['tick_seconds'];
        }
        foreach ($resolved as $k => $v) {
            if (in_array($k, ['tick_seconds'], true)) {
                continue;
            }
            if (!array_key_exists($k, $job)) {
                $job[$k] = $v;
            }
        }

        $onStart = $meta['on_start'] ?? null;
        if ($onStart !== null) {
            $job = LongJobRegistry::invoke($onStart, [$job, $resolved]);
            if (!is_array($job)) {
                throw new RuntimeException('LongJob on_start must return job array');
            }
            $job['id'] = $id;
            $job['type'] = $type;
            $job['status'] = (string)($job['status'] ?? self::STATUS_RUNNING);
        }

        $job['updated_at'] = time();
        LongJobStore::save($job, $dir, ['buffer', 'status_map']);
        return $job;
    }

    /** @return array<string,mixed> */
    public static function get(string $id, ?string $typeOrDir = null): array
    {
        $dir = self::dirForLookup($id, $typeOrDir);
        $job = LongJobStore::get($id, $dir);
        self::assertJobAccess($job);
        return $job;
    }

    /** @return array<string,mixed> */
    public static function tick(string $id, ?string $typeOrDir = null): array
    {
        $dir = self::dirForLookup($id, $typeOrDir);
        // Non-blocking: concurrent tick (proxy retry) returns snapshot; cancel uses JSON CAS.
        $lockFh = LongJobStore::acquireLock($id, $dir, LOCK_EX | LOCK_NB);
        if ($lockFh === null) {
            $job = LongJobStore::get($id, $dir);
            self::assertJobAccess($job);
            return $job;
        }
        try {
            $job = LongJobStore::get($id, $dir);
            self::assertJobAccess($job);
            if (($job['status'] ?? '') !== self::STATUS_RUNNING) {
                return $job;
            }
            $type = (string)($job['type'] ?? '');
            if ($type === '' || !LongJobRegistry::has($type)) {
                throw new RuntimeException('Job type not registered: ' . $type);
            }
            $meta = LongJobRegistry::get($type);
            $tick = $meta['tick'] ?? null;
            if ($tick === null) {
                throw new RuntimeException('No tick handler for ' . $type);
            }
            $storeDir = LongJobRegistry::storeDirFor($type) ?? $dir;
            try {
                @set_time_limit(0);
                $job = LongJobRegistry::invoke($tick, [$job]);
                if (!is_array($job)) {
                    throw new RuntimeException('LongJob tick must return job array');
                }
            } catch (Throwable $e) {
                $job['status'] = self::STATUS_ERROR;
                $job['error'] = $e->getMessage();
                $job['message'] = $e->getMessage();
                self::appendLog($job, $e->getMessage());
            }
            $job['id'] = $id;
            $job['type'] = $type;
            $job['updated_at'] = time();
            $job['elapsed'] = max(0, time() - (int)($job['created_at'] ?? time()));
            // Never overwrite cancelled/done/error written by a concurrent cancel.
            $out = LongJobStore::saveIfRunning($job, $storeDir, ['buffer', 'status_map']);
            return $out['job'];
        } finally {
            LongJobStore::releaseLock($lockFh);
        }
    }

    /** @return array<string,mixed> */
    public static function cancel(string $id, ?string $typeOrDir = null): array
    {
        $dir = self::dirForLookup($id, $typeOrDir);
        // Short exclusive lock on JSON transition; does not wait for tick handler (no deadlock
        // if cancel is invoked from a tick callback). Tick save uses saveIfRunning CAS.
        $job = LongJobStore::get($id, $dir);
        self::assertJobAccess($job);
        if (($job['status'] ?? '') !== self::STATUS_RUNNING) {
            return $job;
        }
        $type = (string)($job['type'] ?? '');
        $meta = ($type !== '' && LongJobRegistry::has($type)) ? LongJobRegistry::get($type) : [];
        $onCancel = $meta['on_cancel'] ?? null;
        if ($onCancel !== null) {
            $job = LongJobRegistry::invoke($onCancel, [$job]);
            if (!is_array($job)) {
                throw new RuntimeException('LongJob on_cancel must return job array');
            }
        }
        $job['id'] = $id;
        if ($type !== '') {
            $job['type'] = $type;
        }
        $job['status'] = self::STATUS_CANCELLED;
        if ((string)($job['message'] ?? '') === '') {
            $job['message'] = class_exists('I18n', false)
                ? t('core.longjob.cancelled')
                : 'Cancelled';
        }
        $job['updated_at'] = time();
        $job['elapsed'] = max(0, time() - (int)($job['created_at'] ?? time()));
        $storeDir = ($type !== '' && LongJobRegistry::has($type))
            ? (LongJobRegistry::storeDirFor($type) ?? $dir)
            : $dir;
        // Force-write cancelled under file LOCK_EX (same as save); tick CAS will honor it.
        LongJobStore::save($job, $storeDir, ['buffer', 'status_map']);
        return $job;
    }

    /**
     * Blocking pump for CLI / platform agent_token (module contexts.cli budget preferred).
     *
     * @return array<string,mixed>
     */
    public static function runUntilDone(string $id, ?string $typeOrDir = null, ?float $tickSeconds = null): array
    {
        $job = self::get($id, $typeOrDir);
        $deadline = time() + 21600;
        while (($job['status'] ?? '') === self::STATUS_RUNNING && time() < $deadline) {
            if ($tickSeconds !== null && $tickSeconds > 0) {
                $job['tick_seconds'] = LongJobRegistry::clampTickSeconds($tickSeconds);
                $type = (string)($job['type'] ?? '');
                $dir = ($type !== '' && LongJobRegistry::has($type))
                    ? LongJobRegistry::storeDirFor($type)
                    : $typeOrDir;
                LongJobStore::save($job, $dir, ['buffer', 'status_map']);
            }
            $job = self::tick($id, $typeOrDir);
        }
        return $job;
    }

    /**
     * Slim public DTO for UI / agent JSON.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function public(array $job): array
    {
        $log = [];
        if (is_array($job['log'] ?? null)) {
            foreach ($job['log'] as $line) {
                if (is_string($line) && $line !== '') {
                    $log[] = $line;
                }
            }
        }
        $progress = (int)($job['progress'] ?? 0);
        $overall = (int)($job['progress_overall'] ?? $progress);
        $detail = (int)($job['progress_detail'] ?? ($job['progress_table'] ?? 0));
        return [
            'id' => (string)($job['id'] ?? ''),
            'type' => (string)($job['type'] ?? ($job['op'] ?? '')),
            'op' => (string)($job['op'] ?? ($job['type'] ?? '')),
            'status' => (string)($job['status'] ?? ''),
            'progress' => $progress > 0 ? $progress : $overall,
            'progress_overall' => $overall,
            'progress_detail' => $detail,
            'progress_table' => (int)($job['progress_table'] ?? $detail),
            'message' => (string)($job['message'] ?? ''),
            'error' => $job['error'] ?? null,
            'result' => $job['result'] ?? null,
            'elapsed' => (int)($job['elapsed'] ?? 0),
            'eta_seconds' => isset($job['eta_seconds']) ? (int)$job['eta_seconds'] : null,
            'bytes' => (int)($job['bytes'] ?? 0),
            'table' => (string)($job['table'] ?? ''),
            'table_idx' => (int)($job['table_idx'] ?? 0),
            'tables_total' => (int)($job['tables_total'] ?? (is_array($job['tables'] ?? null) ? count($job['tables']) : 0)),
            'phase' => (string)($job['phase'] ?? ''),
            'file' => (string)($job['file'] ?? ''),
            'log' => $log,
            'tick_seconds' => isset($job['tick_seconds']) ? (float)$job['tick_seconds'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $job
     */
    public static function appendLog(array &$job, string $line): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }
        $log = is_array($job['log'] ?? null) ? $job['log'] : [];
        $last = $log !== [] ? (string)end($log) : '';
        if ($last === $line) {
            $job['message'] = $line;
            return;
        }
        $log[] = $line;
        if (count($log) > 250) {
            $log = array_slice($log, -250);
        }
        $job['log'] = array_values($log);
        $job['message'] = $line;
    }

    /**
     * Persist helper for domain adapters that mutate job mid-tick.
     * Running snapshots use CAS so a concurrent cancel is not overwritten.
     */
    public static function save(array $job, ?string $dirOrNs = null): void
    {
        $type = (string)($job['type'] ?? '');
        if ($dirOrNs === null && $type !== '' && LongJobRegistry::has($type)) {
            $dirOrNs = LongJobRegistry::storeDirFor($type);
        }
        $job['updated_at'] = time();
        if ((string)($job['status'] ?? '') === self::STATUS_RUNNING) {
            LongJobStore::saveIfRunning($job, $dirOrNs, ['buffer', 'status_map']);
            return;
        }
        LongJobStore::save($job, $dirOrNs, ['buffer', 'status_map']);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private static function assertAccess(array $meta): void
    {
        if (!class_exists('Auth', false) || !Auth::check()) {
            // CLI / agent_token paths may run without session — caller gates separately.
            return;
        }
        $cap = (string)($meta['capability'] ?? '');
        if ($cap !== '' && !Auth::capability($cap)) {
            throw new RuntimeException('Forbidden');
        }
        $perm = (string)($meta['permission'] ?? '');
        if ($perm !== '' && !Auth::can($perm)) {
            throw new RuntimeException('Forbidden');
        }
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function assertJobAccess(array $job): void
    {
        if (!class_exists('Auth', false) || !Auth::check()) {
            return;
        }
        $cap = (string)($job['capability'] ?? '');
        if ($cap !== '' && !Auth::capability($cap)) {
            throw new RuntimeException('Forbidden');
        }
        $perm = (string)($job['permission'] ?? '');
        if ($perm !== '' && !Auth::can($perm)) {
            throw new RuntimeException('Forbidden');
        }
    }

    private static function dirForLookup(string $id, ?string $typeOrDir): ?string
    {
        if ($typeOrDir === null || $typeOrDir === '') {
            return null;
        }
        if (LongJobRegistry::has($typeOrDir)) {
            return LongJobRegistry::storeDirFor($typeOrDir);
        }
        return $typeOrDir;
    }
}
