<?php
declare(strict_types=1);

/**
 * Test-copy replica: manual one-way pull of the primary's latest light/full + CDC packs.
 *
 * Reads only from the replica source FTP (replica_* keys); never writes there.
 * Restores the database only — the primary's cfg.tar and file trees are not applied,
 * so local config.ini / cfg/modules/*.ini (incl. this host's own backup target) stay intact.
 *
 * Long pulls are a tick job (var/backups/replica-job.json) so shared-hosting HTTP/proxies
 * do not kill a single multi-minute request mid-wipe.
 */
final class BackupReplica
{
    private const JOB_FILE = 'replica-job.json';
    private const MAX_JOB_AGE_SEC = 21600; // 6h
    public const BUDGET_SEC = 40;

    /** @return array{ok:bool,error:string} */
    public static function configured(): array
    {
        $ftp = BackupConfig::replicaFtpConfig();
        if (trim($ftp['host']) === '') {
            return ['ok' => false, 'error' => t('backups.replica.err.no_ftp')];
        }
        if (BackupConfig::replicaInstance() === '') {
            return ['ok' => false, 'error' => t('backups.replica.err.no_instance')];
        }
        return ['ok' => true, 'error' => ''];
    }

    private static function source(): BackupFtpTarget
    {
        return BackupFtpTarget::fromArray(BackupConfig::replicaFtpConfig());
    }

    /**
     * Check FTP reachability, find latest baseline + CDC packs to apply, verify passphrase on a small pack.
     *
     * @return array<string,mixed>
     */
    public static function check(bool $verifyPassphrase = true): array
    {
        $cfg = self::configured();
        if (!$cfg['ok']) {
            return ['ok' => false, 'error' => $cfg['error'], 'applied' => BackupConfig::replicaApplied()];
        }
        // Same warehouse as own backups → fail even before enable (so settings mistakes surface early).
        $same = BackupConfig::replicaConflictsWithBackupWriter();
        if (!empty($same['conflict'])) {
            return [
                'ok' => false,
                'error' => t('backups.replica.err.same_target'),
                'code' => 'same_target',
                'applied' => BackupConfig::replicaApplied(),
            ];
        }
        if (StandbyMode::isStandby()) {
            return [
                'ok' => false,
                'error' => t('backups.replica.err.standby'),
                'code' => 'standby',
                'applied' => BackupConfig::replicaApplied(),
            ];
        }
        $target = self::source();
        try {
            $plan = self::planFrom($target);
            if (!empty($plan['error'])) {
                return array_merge($plan, ['ok' => false, 'applied' => BackupConfig::replicaApplied()]);
            }
            $passphrase = ['checked' => false, 'ok' => null, 'pack' => '', 'error' => ''];
            if ($verifyPassphrase) {
                $passphrase = self::verifyPassphrase($target, $plan);
            }
            // Check does not require replica_enabled (verify packs before turning the role on).
            // Pull/preflight still uses replicaRoleGuard(true).
            $role = BackupConfig::replicaRoleGuard(false);
            $enabled = BackupConfig::replicaEnabled();
            return [
                'ok' => $passphrase['ok'] !== false && !empty($role['ok']),
                'error' => $passphrase['ok'] === false
                    ? (string)$passphrase['error']
                    : (empty($role['ok']) ? (string)$role['error'] : ''),
                'code' => empty($role['ok']) ? (string)$role['code'] : '',
                'instance' => BackupConfig::replicaInstance(),
                'baseline' => $plan['baseline'],
                'cdc' => $plan['cdc'],
                'cdc_error' => $plan['cdc_error'],
                'pull_mode' => (string)($plan['pull_mode'] ?? 'full'),
                'passphrase' => $passphrase,
                'role' => $role,
                'replica_enabled' => $enabled,
                'applied' => BackupConfig::replicaApplied(),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'applied' => BackupConfig::replicaApplied()];
        } finally {
            $target->close();
        }
    }

    /**
     * Blocking pull (CLI). Loops the tick job until done/error.
     *
     * @return array<string,mixed>
     */
    public static function pull(): array
    {
        $start = self::jobStart(true);
        if (($start['status'] ?? '') === 'done') {
            return [
                'ok' => true,
                'error' => '',
                'message' => (string)($start['message'] ?? ''),
                'pull_mode' => (string)($start['pull_mode'] ?? 'noop'),
                'baseline' => (string)($start['baseline_name'] ?? ''),
                'diff' => '',
                'steps' => (array)($start['steps'] ?? []),
                'applied' => BackupConfig::replicaApplied(),
            ];
        }
        if (($start['status'] ?? '') === 'error' || empty($start['ok'])) {
            return [
                'ok' => false,
                'error' => (string)($start['error'] ?? 'start failed'),
                'steps' => (array)($start['steps'] ?? []),
            ];
        }
        $deadline = time() + self::MAX_JOB_AGE_SEC;
        while (time() < $deadline) {
            $j = self::jobAdvance(90);
            $status = (string)($j['status'] ?? '');
            if ($status === 'done') {
                return [
                    'ok' => true,
                    'error' => '',
                    'pull_mode' => (string)($j['pull_mode'] ?? ''),
                    'baseline' => (string)($j['baseline_name'] ?? ''),
                    'diff' => (string)($j['applied_diff'] ?? ''),
                    'steps' => (array)($j['steps'] ?? []),
                    'applied' => BackupConfig::replicaApplied(),
                ];
            }
            if ($status === 'paused') {
                // CLI: keep going after a brief pause (FTP blip).
                usleep(500000);
                continue;
            }
            if ($status === 'error' || $status === '') {
                return [
                    'ok' => false,
                    'error' => (string)($j['error'] ?? 'pull failed'),
                    'steps' => (array)($j['steps'] ?? []),
                    'phase' => (string)($j['phase'] ?? ''),
                ];
            }
        }
        self::jobFail(t('backups.replica.err.stale'));
        $j = self::jobGet() ?? [];
        return [
            'ok' => false,
            'error' => (string)($j['error'] ?? t('backups.replica.err.stale')),
            'steps' => (array)($j['steps'] ?? []),
        ];
    }

    /** Cron crumb while a replica pull is in progress (running or paused). */
    public static function cronAdvance(): array
    {
        $j = self::jobGet();
        $st = is_array($j) ? (string)($j['status'] ?? '') : '';
        if ($j === null || ($st !== 'running' && $st !== 'paused')) {
            return ['ok' => true, 'processed' => 0, 'message' => 'idle'];
        }
        $started = (int)($j['started_at'] ?? 0);
        if ($started > 0 && (time() - $started) > self::MAX_JOB_AGE_SEC) {
            self::jobFail(t('backups.replica.err.stale'));
            return ['ok' => false, 'processed' => 1, 'message' => 'stale', 'status' => 'error'];
        }
        $r = self::jobResumeAndAdvance(self::BUDGET_SEC);
        return [
            'ok' => !in_array((string)($r['status'] ?? ''), ['error'], true),
            'processed' => 1,
            'message' => (string)($r['phase'] ?? ''),
            'status' => (string)($r['status'] ?? ''),
            'phase' => (string)($r['phase'] ?? ''),
        ];
    }

    /**
     * All checks before any download/DROP: FTP, plan, passphrase, foreign tables.
     *
     * @return array<string,mixed>
     */
    public static function preflight(): array
    {
        $role = BackupConfig::replicaRoleGuard(true);
        if (empty($role['ok'])) {
            return ['ok' => false, 'error' => (string)$role['error'], 'code' => (string)$role['code']];
        }
        $cfg = self::configured();
        if (!$cfg['ok']) {
            return ['ok' => false, 'error' => $cfg['error']];
        }
        // FTP + latest packs + decrypt probe (fails fast when .enc and passphrase empty/wrong).
        $check = self::check(true);
        if (empty($check['ok'])) {
            return [
                'ok' => false,
                'error' => (string)($check['error'] ?? t('backups.replica.err.preflight')),
                'check' => $check,
            ];
        }
        if (!empty($check['cdc_error'])) {
            return ['ok' => false, 'error' => (string)$check['cdc_error'], 'check' => $check];
        }
        $baseline = is_array($check['baseline'] ?? null) ? $check['baseline'] : null;
        if ($baseline === null) {
            return [
                'ok' => false,
                'error' => t('backups.replica.err.no_baseline', ['instance' => BackupConfig::replicaInstance()]),
                'check' => $check,
            ];
        }
        // Encrypted packs need a non-empty passphrase even when the small-pack probe was skipped.
        $enc = static function (string $name): bool {
            return (bool)preg_match('/\.enc$/i', $name);
        };
        $needsPass = $enc((string)($baseline['name'] ?? ''));
        foreach ((array)($check['cdc']['packs'] ?? []) as $p) {
            if (is_array($p) && $enc((string)($p['name'] ?? ''))) {
                $needsPass = true;
                break;
            }
        }
        if ($needsPass && BackupConfig::replicaPassphrase() === '') {
            return ['ok' => false, 'error' => t('backups.replica.err.no_passphrase'), 'check' => $check];
        }
        $pullMode = (string)($check['pull_mode'] ?? 'full');
        // Full redeploy drops base_* — refuse if shared-DB foreign tables hold rows.
        if ($pullMode === 'full') {
            $foreign = BackupRestore::foreignTablesWithRows();
            if ($foreign !== []) {
                return [
                    'ok' => false,
                    'error' => t('backups.replica.err.foreign', [
                        'tables' => implode(', ', array_slice($foreign, 0, 5)),
                    ]),
                    'check' => $check,
                ];
            }
        }
        return ['ok' => true, 'error' => '', 'check' => $check, 'pull_mode' => $pullMode];
    }

    /**
     * Start a replica pull job (downloads first; DROP only after packs are local).
     *
     * @return array<string,mixed>
     */
    /**
     * @param bool $confirmWipe Required for full redeploy (DROP base_*); ignored for incr/noop/resume.
     */
    public static function jobStart(bool $confirmWipe = false): array
    {
        $existing = self::jobGet();
        $existStatus = is_array($existing) ? (string)($existing['status'] ?? '') : '';
        // Resume running or paused (FTP blip) — keep work dirs / phase.
        if ($existing !== null && ($existStatus === 'running' || $existStatus === 'paused')) {
            if ($existStatus === 'paused') {
                $existing['status'] = 'running';
                $existing['error'] = '';
                $existing['finished_at'] = 0;
                if ((string)($existing['passphrase'] ?? '') === '') {
                    $existing['passphrase'] = BackupConfig::replicaPassphrase();
                }
            }
            $token = (string)($existing['tick_token'] ?? '');
            if ($token === '') {
                $existing['tick_token'] = bin2hex(random_bytes(32));
                $token = (string)$existing['tick_token'];
            }
            self::jobSave($existing);
            $pub = self::publicJob($existing);
            $pub['ok'] = true;
            $pub['resumed'] = true;
            $pub['tick_token'] = $token;
            return $pub;
        }

        $pre = self::preflight();
        if (empty($pre['ok'])) {
            return [
                'ok' => false,
                'status' => 'error',
                'error' => (string)($pre['error'] ?? t('backups.replica.err.preflight')),
                'check' => $pre['check'] ?? null,
            ];
        }

        $check = is_array($pre['check'] ?? null) ? $pre['check'] : [];
        $pullMode = (string)($check['pull_mode'] ?? ($pre['pull_mode'] ?? 'full'));
        $baseline = is_array($check['baseline'] ?? null) ? $check['baseline'] : [];
        $cdcPacks = (array)($check['cdc']['packs'] ?? []);

        if ($pullMode === 'full' && !$confirmWipe) {
            return [
                'ok' => false,
                'status' => 'error',
                'error' => t('backups.replica.err.confirm'),
                'pull_mode' => 'full',
            ];
        }

        if ($pullMode === 'noop') {
            return [
                'ok' => true,
                'status' => 'done',
                'phase' => 'done',
                'error' => '',
                'message' => t('backups.replica.up_to_date'),
                'pull_mode' => 'noop',
                'baseline_name' => (string)($baseline['name'] ?? ''),
                'relogin' => false,
                'progress' => self::progress([
                    'status' => 'done',
                    'phase' => 'done',
                    'pull_mode' => 'noop',
                ]),
                'applied' => BackupConfig::replicaApplied(),
            ];
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ignore_user_abort(true);

        $phase = $pullMode === 'incr' ? 'download_cdc' : 'download_baseline';
        $job = [
            'status' => 'running',
            'phase' => $phase,
            'pull_mode' => $pullMode,
            'error' => '',
            'started_at' => time(),
            'finished_at' => 0,
            'tick_token' => bin2hex(random_bytes(32)),
            'passphrase' => BackupConfig::replicaPassphrase(),
            'plan' => [
                'error' => '',
                'baseline' => $baseline,
                'cdc' => ['mode' => (string)($check['cdc']['mode'] ?? ''), 'packs' => $cdcPacks],
                'cdc_error' => '',
                'pull_mode' => $pullMode,
            ],
            'work_dirs' => [],
            'baseline_local_dir' => '',
            'baseline_pack' => '',
            'baseline_name' => (string)($baseline['name'] ?? ''),
            'download_local' => 0,
            'download_remote' => 0,
            'cdc_queue' => $cdcPacks,
            'cdc_idx' => 0,
            'cdc_local' => [],
            'db_job_id' => '',
            'applied_diff' => '',
            'steps' => [
                [
                    'step' => 'preflight',
                    'status' => 'ok',
                    'baseline' => (string)($baseline['name'] ?? ''),
                    'pull_mode' => $pullMode,
                ],
            ],
            'wiped' => false,
        ];
        self::jobSave($job);
        $pub = self::publicJob($job);
        $pub['ok'] = true;
        $pub['resumed'] = false;
        $pub['tick_token'] = (string)$job['tick_token'];
        return $pub;
    }

    /** Validate the one-shot tick token (needed after wipe — session user is gone). */
    public static function jobTokenOk(string $token): bool
    {
        $token = trim($token);
        if ($token === '' || strlen($token) < 32) {
            return false;
        }
        $job = self::jobGet();
        if ($job === null) {
            return false;
        }
        $want = (string)($job['tick_token'] ?? '');
        $st = (string)($job['status'] ?? '');
        // Allow tick while paused so «Повторить» can resume in one call.
        if ($st !== 'running' && $st !== 'paused') {
            return $want !== '' && hash_equals($want, $token);
        }
        return $want !== '' && hash_equals($want, $token);
    }

    /** Resume a paused job (FTP blip) and advance one crumb. */
    public static function jobResumeAndAdvance(int $budgetSec = 0): array
    {
        $job = self::jobGet();
        if ($job === null) {
            return ['ok' => false, 'status' => 'error', 'error' => t('backups.replica.err.no_job')];
        }
        if ((string)($job['status'] ?? '') === 'paused') {
            $job['status'] = 'running';
            $job['error'] = '';
            $job['finished_at'] = 0;
            if ((string)($job['passphrase'] ?? '') === '') {
                $job['passphrase'] = BackupConfig::replicaPassphrase();
            }
            self::jobSave($job);
        }
        return self::jobAdvance($budgetSec);
    }

    /**
     * Advance the running job by one crumb (~budget seconds).
     *
     * @return array<string,mixed>
     */
    public static function jobAdvance(int $budgetSec = 0): array
    {
        if ($budgetSec <= 0) {
            $budgetSec = self::BUDGET_SEC;
        }
        $job = self::jobGet();
        if ($job === null) {
            return ['ok' => false, 'status' => 'error', 'error' => t('backups.replica.err.no_job')];
        }
        $st0 = (string)($job['status'] ?? '');
        if ($st0 === 'paused') {
            $job['status'] = 'running';
            $job['error'] = '';
            $job['finished_at'] = 0;
            if ((string)($job['passphrase'] ?? '') === '') {
                $job['passphrase'] = BackupConfig::replicaPassphrase();
            }
            self::jobSave($job);
            $st0 = 'running';
        }
        if ($st0 !== 'running') {
            return self::publicJob($job);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ignore_user_abort(true);
        @ini_set('memory_limit', '512M');

        try {
            $phase = (string)($job['phase'] ?? 'plan');
            if ($phase === 'plan') {
                // Legacy jobs started before preflight-at-start — still supported.
                $job = self::phasePlan($job);
            } elseif ($phase === 'download_baseline') {
                $job = self::phaseDownloadBaseline($job, $budgetSec);
            } elseif ($phase === 'prepare_baseline') {
                $job = self::phasePrepareBaseline($job);
            } elseif ($phase === 'download_cdc') {
                $job = self::phaseDownloadCdc($job, $budgetSec);
            } elseif ($phase === 'wipe') {
                $job = self::phaseWipe($job);
            } elseif ($phase === 'restore') {
                $job = self::phaseRestore($job, $budgetSec);
            } elseif ($phase === 'apply_cdc') {
                $job = self::phaseApplyCdc($job);
            } elseif ($phase === 'finish') {
                $job = self::phaseFinish($job);
            } else {
                throw new RuntimeException('Unknown replica phase: ' . $phase);
            }
        } catch (Throwable $e) {
            // Pause (not hard-fail): keep packs/phase so UI «Повторить» can continue after FTP blips.
            $job['status'] = 'paused';
            $job['error'] = $e->getMessage();
            $job['finished_at'] = time();
            try {
                self::jobSave($job);
            } catch (Throwable $e2) {
                // ignore secondary save failure
            }
        }
        try {
            return self::publicJob($job);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status' => (string)($job['status'] ?? 'error'),
                'phase' => (string)($job['phase'] ?? ''),
                'error' => $e->getMessage(),
                'progress' => [
                    'phases' => [],
                    'phase' => (string)($job['phase'] ?? ''),
                    'index' => 0,
                    'pct' => null,
                ],
            ];
        }
    }

    /** @return array<string,mixed>|null */
    public static function jobGet(): ?array
    {
        $path = self::jobPath();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    /** Progress payload for UI (no secrets). */
    public static function progress(?array $job = null): array
    {
        $job = $job ?? self::jobGet() ?? [];
        $pullMode = (string)($job['pull_mode'] ?? 'full');
        if ($pullMode === 'incr') {
            $phases = ['download_cdc', 'apply_cdc', 'finish', 'done'];
        } elseif ($pullMode === 'noop') {
            $phases = ['done'];
        } else {
            $phases = [
                'plan',
                'download_baseline',
                'prepare_baseline',
                'download_cdc',
                'wipe',
                'restore',
                'apply_cdc',
                'finish',
                'done',
            ];
        }
        $phase = (string)($job['phase'] ?? 'plan');
        $status = (string)($job['status'] ?? '');
        if ($status === 'done') {
            $phase = 'done';
        }
        $index = array_search($phase, $phases, true);
        if ($index === false) {
            $index = 0;
        }
        $pct = null;
        $local = (int)($job['download_local'] ?? 0);
        $remote = (int)($job['download_remote'] ?? 0);
        if ($phase === 'download_baseline' && $remote > 0) {
            $pct = (int)min(99, floor(100 * $local / $remote));
        } elseif ($phase === 'restore' && !empty($job['db_job_id'])) {
            try {
                $dj = DbJobs::jobGet((string)$job['db_job_id']);
                if (is_array($dj)) {
                    if (isset($dj['progress_overall'])) {
                        $pct = (int)$dj['progress_overall'];
                    } elseif (isset($dj['progress'])) {
                        $pct = (int)$dj['progress'];
                    } elseif (isset($dj['pct'])) {
                        $pct = (int)$dj['pct'];
                    }
                }
            } catch (Throwable $e) {
                $pct = null;
            }
        } elseif ($phase === 'download_cdc') {
            $n = count((array)($job['cdc_queue'] ?? []));
            $i = (int)($job['cdc_idx'] ?? 0);
            if ($n > 0) {
                $pct = (int)min(99, floor(100 * $i / $n));
            }
        }
        return [
            'phases' => $phases,
            'phase' => $phase,
            'index' => (int)$index,
            'pct' => $pct,
            'bytes' => $local,
            'remote_bytes' => $remote,
            'pull_mode' => $pullMode,
            'status' => $status,
        ];
    }

    // ——— phases ———

    /** @param array<string,mixed> $job */
    private static function phasePlan(array $job): array
    {
        $pre = self::preflight();
        if (empty($pre['ok'])) {
            throw new RuntimeException((string)($pre['error'] ?? t('backups.replica.err.preflight')));
        }
        $check = is_array($pre['check'] ?? null) ? $pre['check'] : [];
        $baseline = is_array($check['baseline'] ?? null) ? $check['baseline'] : [];
        $cdcPacks = (array)($check['cdc']['packs'] ?? []);
        $job['plan'] = [
            'error' => '',
            'baseline' => $baseline,
            'cdc' => ['mode' => (string)($check['cdc']['mode'] ?? ''), 'packs' => $cdcPacks],
            'cdc_error' => '',
        ];
        $job['baseline_name'] = (string)($baseline['name'] ?? '');
        $job['cdc_queue'] = $cdcPacks;
        $job['steps'][] = ['step' => 'plan', 'status' => 'ok', 'baseline' => $job['baseline_name']];
        $job['phase'] = 'download_baseline';
        self::jobSave($job);
        return $job;
    }

    /** @param array<string,mixed> $job */
    private static function phaseDownloadBaseline(array $job, int $budgetSec): array
    {
        $plan = is_array($job['plan'] ?? null) ? $job['plan'] : [];
        $baseline = is_array($plan['baseline'] ?? null) ? $plan['baseline'] : [];
        $path = (string)($baseline['path'] ?? '');
        if ($path === '') {
            throw new RuntimeException(t('backups.replica.err.no_baseline', [
                'instance' => BackupConfig::replicaInstance(),
            ]));
        }
        $target = self::source();
        try {
            $dl = BackupRestore::downloadRemote($path, $budgetSec, $target);
            $work = (string)($dl['local_dir'] ?? '');
            if ($work !== '' && !in_array($work, (array)$job['work_dirs'], true)) {
                $job['work_dirs'][] = $work;
            }
            $job['baseline_pack'] = (string)($dl['pack_path'] ?? '');
            $job['download_local'] = (int)($dl['local_size'] ?? 0);
            $job['download_remote'] = (int)($dl['remote_size'] ?? 0);
            if (empty($dl['done']) && empty($dl['ok'])) {
                // still downloading
                self::jobSave($job);
                return $job;
            }
            if (empty($dl['ok'])) {
                throw new RuntimeException('download failed: ' . $path);
            }
            $job['steps'][] = [
                'step' => 'download_baseline',
                'status' => 'ok',
                'pack' => $job['baseline_name'],
                'bytes' => $job['download_local'],
            ];
            $job['phase'] = 'prepare_baseline';
            self::jobSave($job);
            return $job;
        } finally {
            $target->close();
        }
    }

    /** @param array<string,mixed> $job */
    private static function phasePrepareBaseline(array $job): array
    {
        $pack = (string)($job['baseline_pack'] ?? '');
        if ($pack === '' || !is_file($pack)) {
            throw new RuntimeException(t('backups.err.restore_pack_missing'));
        }
        $prep = BackupRestore::prepareLocal($pack, (string)($job['passphrase'] ?? ''));
        if (empty($prep['ok'])) {
            throw new RuntimeException('prepare failed: ' . basename($pack));
        }
        $dir = (string)$prep['local_dir'];
        if (BackupStandby::findDbDump($dir) === '') {
            throw new RuntimeException(t('backups.replica.err.no_dump', ['pack' => (string)$job['baseline_name']]));
        }
        $job['baseline_local_dir'] = $dir;
        $job['steps'][] = ['step' => 'prepare_baseline', 'status' => 'ok'];
        $job['phase'] = count((array)($job['cdc_queue'] ?? [])) > 0 ? 'download_cdc' : 'wipe';
        self::jobSave($job);
        return $job;
    }

    /** @param array<string,mixed> $job */
    private static function phaseDownloadCdc(array $job, int $budgetSec): array
    {
        $queue = (array)($job['cdc_queue'] ?? []);
        $idx = (int)($job['cdc_idx'] ?? 0);
        if ($idx >= count($queue)) {
            // Incr: packs already on disk → apply. Full: wipe+restore baseline first.
            $job['phase'] = ((string)($job['pull_mode'] ?? 'full') === 'incr')
                ? (count((array)($job['cdc_local'] ?? [])) > 0 ? 'apply_cdc' : 'finish')
                : 'wipe';
            self::jobSave($job);
            return $job;
        }
        $p = is_array($queue[$idx] ?? null) ? $queue[$idx] : [];
        $remotePath = (string)($p['path'] ?? '');
        $name = (string)($p['name'] ?? '');
        $target = self::source();
        try {
            $dl = BackupRestore::downloadRemote($remotePath, $budgetSec, $target);
            $work = (string)($dl['local_dir'] ?? '');
            if ($work !== '' && !in_array($work, (array)$job['work_dirs'], true)) {
                $job['work_dirs'][] = $work;
            }
            $job['download_local'] = (int)($dl['local_size'] ?? 0);
            $job['download_remote'] = (int)($dl['remote_size'] ?? 0);
            if (empty($dl['done']) && empty($dl['ok'])) {
                self::jobSave($job);
                return $job;
            }
            if (empty($dl['ok'])) {
                throw new RuntimeException('download failed: ' . $remotePath);
            }
            $pack = (string)($dl['pack_path'] ?? '');
            $prep = BackupRestore::prepareLocal($pack !== '' ? $pack : $work, (string)($job['passphrase'] ?? ''));
            if (empty($prep['ok'])) {
                throw new RuntimeException('prepare failed: ' . $name);
            }
            $gz = BackupStandby::findFile((string)$prep['local_dir'], 'incr.sql.gz');
            if ($gz === '') {
                throw new RuntimeException('incr.sql.gz missing in ' . $name);
            }
            $job['cdc_local'][] = ['name' => $name, 'gz' => $gz];
            $job['cdc_idx'] = $idx + 1;
            $job['steps'][] = ['step' => 'download_cdc', 'status' => 'ok', 'pack' => $name];
            if ($job['cdc_idx'] >= count($queue)) {
                $job['phase'] = ((string)($job['pull_mode'] ?? 'full') === 'incr')
                    ? 'apply_cdc'
                    : 'wipe';
                if ((string)$job['phase'] === 'apply_cdc') {
                    $job['cdc_idx'] = 0; // reuse as apply index
                }
            }
            self::jobSave($job);
            return $job;
        } finally {
            $target->close();
        }
    }

    /** @param array<string,mixed> $job */
    private static function phaseWipe(array $job): array
    {
        // Re-check foreign tables right before DROP.
        $foreign = BackupRestore::foreignTablesWithRows();
        if ($foreign !== []) {
            throw new RuntimeException(t('backups.replica.err.foreign', [
                'tables' => implode(', ', array_slice($foreign, 0, 5)),
            ]));
        }
        BackupStandby::emptyOwnedTables();
        $job['wiped'] = true;
        $job['steps'][] = ['step' => 'drop_base', 'status' => 'ok'];
        $job['phase'] = 'restore';
        self::jobSave($job);
        return $job;
    }

    /** @param array<string,mixed> $job */
    private static function phaseRestore(array $job, int $budgetSec): array
    {
        $dir = (string)($job['baseline_local_dir'] ?? '');
        if ($dir === '' || !is_dir($dir)) {
            throw new RuntimeException(t('backups.err.restore_no_db'));
        }
        $jobId = (string)($job['db_job_id'] ?? '');
        // One DbJobs crumb per HTTP tick — restore ticks default to 240s and blow proxy timeouts.
        $crumb = max(8.0, min(25.0, (float)$budgetSec));
        if ($jobId === '') {
            $jobId = BackupRestore::startDbRestore($dir, $crumb);
            $job['db_job_id'] = $jobId;
            self::jobSave($job);
        }
        $dj = DbJobs::jobTick($jobId);
        $st = (string)($dj['status'] ?? '');
        if ($st === 'running') {
            self::jobSave($job);
            return $job;
        }
        if ($st !== 'done') {
            throw new RuntimeException((string)($dj['error'] ?? 'db restore failed'));
        }
        BackupRepository::cancelAllRunning();
        $job['steps'][] = ['step' => 'restore_db', 'status' => 'ok', 'job_id' => $jobId];
        $job['phase'] = count((array)($job['cdc_local'] ?? [])) > 0 ? 'apply_cdc' : 'finish';
        $job['cdc_idx'] = 0; // reuse as apply index
        self::jobSave($job);
        return $job;
    }

    /** @param array<string,mixed> $job */
    private static function phaseApplyCdc(array $job): array
    {
        $local = (array)($job['cdc_local'] ?? []);
        $idx = (int)($job['cdc_idx'] ?? 0);
        if ($idx >= count($local)) {
            $job['phase'] = 'finish';
            self::jobSave($job);
            return $job;
        }
        $c = is_array($local[$idx] ?? null) ? $local[$idx] : [];
        $gz = (string)($c['gz'] ?? '');
        $name = (string)($c['name'] ?? '');
        $raw = @gzdecode((string)@file_get_contents($gz));
        if ($raw === false) {
            throw new RuntimeException('gunzip failed: ' . $name);
        }
        $sqlPath = dirname($gz) . '/_replica_incr.sql';
        file_put_contents($sqlPath, $raw);
        $ap = BackupCdc::applyIncrSqlFile($sqlPath);
        @unlink($sqlPath);
        if (empty($ap['ok'])) {
            throw new RuntimeException((string)($ap['error'] ?? 'apply failed') . ' (' . $name . ')');
        }
        $job['applied_diff'] = $name;
        $job['cdc_idx'] = $idx + 1;
        $job['steps'][] = [
            'step' => 'apply_cdc',
            'status' => 'ok',
            'pack' => $name,
            'statements' => (int)($ap['statements'] ?? 0),
        ];
        if ($job['cdc_idx'] >= count($local)) {
            $job['phase'] = 'finish';
        }
        self::jobSave($job);
        return $job;
    }

    /** @param array<string,mixed> $job */
    private static function phaseFinish(array $job): array
    {
        try {
            $mig = AgentOps::migrateRun(null);
            $job['steps'][] = ['step' => 'migrate', 'status' => !empty($mig['ok']) ? 'ok' : 'error'];
        } catch (Throwable $e) {
            $job['steps'][] = ['step' => 'migrate', 'status' => 'error', 'error' => $e->getMessage()];
        }
        if (!BackupConfig::cdcEnabled()) {
            BackupCdc::uninstall();
            if (BackupCdc::journalReady()) {
                BackupCdc::pruneJournal(BackupCdc::maxJournalId());
            }
            $job['steps'][] = ['step' => 'cdc_cleanup', 'status' => 'ok'];
        }
        $baselineName = (string)($job['baseline_name'] ?? '');
        if ($baselineName === '') {
            $baselineName = (string)(BackupConfig::replicaApplied()['full'] ?? '');
        }
        $diffName = (string)($job['applied_diff'] ?? '');
        // Incr-only pull may apply nothing new if queue emptied mid-flight — keep previous diff mark.
        if ($diffName === '' && (string)($job['pull_mode'] ?? '') === 'incr') {
            $diffName = (string)(BackupConfig::replicaApplied()['diff'] ?? '');
        }
        BackupConfig::setReplicaApplied($baselineName, $diffName);
        self::jobCleanupWork($job);
        $job['passphrase'] = '';
        $job['tick_token'] = '';
        $job['status'] = 'done';
        $job['phase'] = 'done';
        $job['finished_at'] = time();
        $job['error'] = '';
        self::jobSave($job);
        return $job;
    }

    // ——— job file helpers ———

    private static function jobPath(): string
    {
        return BackupConfig::workDir() . '/' . self::JOB_FILE;
    }

    /** @param array<string,mixed> $job */
    private static function jobSave(array $job): void
    {
        $path = self::jobPath();
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Cannot encode replica job');
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot write replica job');
        }
    }

    private static function jobFail(string $error): void
    {
        $job = self::jobGet() ?? [
            'status' => 'error',
            'phase' => 'error',
            'steps' => [],
            'started_at' => time(),
        ];
        $job['status'] = 'error';
        $job['error'] = $error;
        $job['finished_at'] = time();
        $job['passphrase'] = '';
        self::jobCleanupWork($job);
        self::jobSave($job);
    }

    /** @param array<string,mixed> $job */
    private static function jobCleanupWork(array &$job): void
    {
        foreach ((array)($job['work_dirs'] ?? []) as $d) {
            if (is_string($d) && $d !== '') {
                BackupStandby::rmTree($d);
            }
        }
        $job['work_dirs'] = [];
    }

    /**
     * Strip secrets before emitting to UI/agent.
     *
     * @param array<string,mixed>|null $job
     * @return array<string,mixed>
     */
    public static function jobPublic(?array $job = null): array
    {
        $job = $job ?? self::jobGet();
        if ($job === null) {
            return ['ok' => false, 'status' => 'error', 'error' => t('backups.replica.err.no_job')];
        }
        return self::publicJob($job);
    }

    /**
     * Strip secrets before emitting to UI/agent.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function publicJob(array $job): array
    {
        // Slim payload for UI — no secrets, no huge nested plan/paths.
        $status = (string)($job['status'] ?? '');
        $pullMode = (string)($job['pull_mode'] ?? 'full');
        $tokenAlive = (string)($job['tick_token'] ?? '') !== '';
        return [
            'ok' => $status !== 'error',
            'status' => $status,
            'phase' => (string)($job['phase'] ?? ''),
            'error' => (string)($job['error'] ?? ''),
            'baseline_name' => (string)($job['baseline_name'] ?? ''),
            'applied_diff' => (string)($job['applied_diff'] ?? ''),
            'pull_mode' => $pullMode,
            'wiped' => !empty($job['wiped']),
            'resumable' => $tokenAlive && ($status === 'paused' || $status === 'running'),
            'started_at' => (int)($job['started_at'] ?? 0),
            'finished_at' => (int)($job['finished_at'] ?? 0),
            'download_local' => (int)($job['download_local'] ?? 0),
            'download_remote' => (int)($job['download_remote'] ?? 0),
            'steps' => array_slice((array)($job['steps'] ?? []), -20),
            'progress' => self::progress($job),
            'applied' => BackupConfig::replicaApplied(),
            // Full redeploy replaces users — session must die. Incr keeps local session.
            'relogin' => $status === 'done' && $pullMode === 'full',
        ];
    }

    /**
     * Latest baseline (light/full) + CDC packs that belong to it.
     *
     * pull_mode: full (new light) | incr (same light, new diff/chain) | noop (already current).
     *
     * @return array{
     *   error:string,
     *   baseline:?array<string,mixed>,
     *   cdc:array{mode:string,packs:list<array<string,mixed>>},
     *   cdc_error:string,
     *   pull_mode:string
     * }
     */
    private static function planFrom(BackupFtpTarget $target): array
    {
        $sets = $target->listSets(BackupConfig::replicaInstance());
        $baseline = null;
        $incrs = [];
        foreach ($sets as $s) {
            $k = (string)($s['kind'] ?? '');
            if ($k === 'incr') {
                $incrs[] = $s;
            } elseif (($k === 'pack' || $k === 'light') && $baseline === null) {
                $baseline = $s;
            }
        }
        if ($baseline === null) {
            return [
                'error' => t('backups.replica.err.no_baseline', ['instance' => BackupConfig::replicaInstance()]),
                'baseline' => null,
                'cdc' => ['mode' => '', 'packs' => []],
                'cdc_error' => '',
                'pull_mode' => 'full',
            ];
        }
        $baseName = (string)$baseline['name'];
        $baseStamp = '';
        if (preg_match('/^(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})/', $baseName, $m)) {
            $baseStamp = $m[1];
        }
        // Only CDC packs produced after this baseline.
        $after = [];
        foreach ($incrs as $s) {
            $name = (string)($s['name'] ?? '');
            if ($baseStamp !== '' && preg_match('/^(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})/', $name, $sm)
                && strcmp($sm[1], $baseStamp) < 0
            ) {
                continue;
            }
            $after[] = $s;
        }

        $applied = BackupConfig::replicaApplied();
        $appliedFull = (string)($applied['full'] ?? '');
        $sameBase = $appliedFull !== '' && $appliedFull === $baseName;
        $appliedSeq = 0;
        if ($sameBase && (string)($applied['diff'] ?? '') !== '') {
            $ap = BackupFtpTarget::parsePackName((string)$applied['diff']);
            $appliedSeq = (int)($ap['seq'] ?? 0);
        }
        // Same baseline → only packs after last applied seq; new light → all packs for that light.
        $sel = BackupStandby::selectPendingCdcPacks(
            $after,
            0,
            $sameBase ? $appliedSeq : 0,
            $baseName
        );
        $packs = [];
        $mode = '';
        foreach ((array)($sel['packs'] ?? []) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $parsed = BackupFtpTarget::parsePackName((string)$p['name']);
            $mode = (string)($parsed['cdc_mode'] ?? '');
            $packs[] = [
                'name' => (string)$p['name'],
                'path' => (string)$p['path'],
                'bytes' => (int)($p['bytes'] ?? 0),
                'seq' => (int)($parsed['seq'] ?? 0),
            ];
        }
        if ($sameBase) {
            $pullMode = ($packs === [] && (string)($sel['error'] ?? '') === '') ? 'noop' : 'incr';
        } else {
            $pullMode = 'full';
        }
        return [
            'error' => '',
            'baseline' => [
                'name' => $baseName,
                'path' => (string)$baseline['path'],
                'bytes' => (int)($baseline['bytes'] ?? 0),
                'kind' => (string)$baseline['kind'],
                'stamp' => $baseStamp,
            ],
            'cdc' => ['mode' => $mode, 'packs' => $packs],
            'cdc_error' => (string)($sel['error'] ?? ''),
            'pull_mode' => $pullMode,
        ];
    }

    /**
     * Decrypt the smallest relevant pack to prove the passphrase (CDC packs are KB-sized).
     *
     * @param array<string,mixed> $plan
     * @return array{checked:bool,ok:?bool,pack:string,error:string}
     */
    private static function verifyPassphrase(BackupFtpTarget $target, array $plan): array
    {
        $packs = (array)($plan['cdc']['packs'] ?? []);
        $probe = $packs !== [] ? $packs[count($packs) - 1] : null;
        $baseline = is_array($plan['baseline'] ?? null) ? $plan['baseline'] : null;
        $encrypted = static function (string $name): bool {
            return (bool)preg_match('/\.enc$/i', $name);
        };
        if ($probe === null) {
            $needs = $baseline !== null && $encrypted((string)$baseline['name']);
            if ($needs && BackupConfig::replicaPassphrase() === '') {
                return ['checked' => false, 'ok' => false, 'pack' => '', 'error' => t('backups.replica.err.no_passphrase')];
            }
            return ['checked' => false, 'ok' => null, 'pack' => '', 'error' => ''];
        }
        if (!$encrypted((string)$probe['name'])) {
            return ['checked' => true, 'ok' => true, 'pack' => (string)$probe['name'], 'error' => ''];
        }
        if (BackupConfig::replicaPassphrase() === '') {
            return ['checked' => false, 'ok' => false, 'pack' => (string)$probe['name'], 'error' => t('backups.replica.err.no_passphrase')];
        }
        $o = self::openPack($target, (string)$probe['path'], BackupConfig::replicaPassphrase());
        BackupStandby::rmTree((string)($o['work_dir'] ?? ''));
        $ok = !empty($o['ok']);
        return [
            'checked' => true,
            'ok' => $ok,
            'pack' => (string)$probe['name'],
            'error' => $ok ? '' : t('backups.replica.err.passphrase', ['error' => (string)($o['error'] ?? '')]),
        ];
    }

    /**
     * Download + decrypt + unpack one pack from the replica source (small packs / passphrase probe).
     *
     * @return array{ok:bool,dir:string,work_dir:string,error:string}
     */
    private static function openPack(BackupFtpTarget $target, string $remotePath, string $passphrase): array
    {
        $workDir = '';
        try {
            $dl = BackupRestore::downloadRemote($remotePath, 900, $target);
            $workDir = (string)($dl['local_dir'] ?? '');
            if (empty($dl['ok'])) {
                return ['ok' => false, 'dir' => '', 'work_dir' => $workDir, 'error' => 'download failed: ' . $remotePath];
            }
            $pack = (string)($dl['pack_path'] ?? '');
            $prep = BackupRestore::prepareLocal($pack !== '' ? $pack : $workDir, $passphrase);
            if (empty($prep['ok'])) {
                return ['ok' => false, 'dir' => '', 'work_dir' => $workDir, 'error' => 'prepare failed: ' . basename($remotePath)];
            }
            return ['ok' => true, 'dir' => (string)$prep['local_dir'], 'work_dir' => $workDir, 'error' => ''];
        } catch (Throwable $e) {
            return ['ok' => false, 'dir' => '', 'work_dir' => $workDir, 'error' => $e->getMessage()];
        }
    }

}
