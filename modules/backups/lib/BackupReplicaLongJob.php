<?php
declare(strict_types=1);

/**
 * LongJob adapters for replica check (session LongJob store) and pull (domain replica-job.json).
 * Pull ticks after wipe stay on public /backups/replica/tick + tick_token.
 */
final class BackupReplicaLongJob
{
    public const TYPE_CHECK = 'backups.replica.check';
    public const TYPE_PULL = 'backups.replica.pull';

    public const SESSION_CHECK = 'freesaas_replica_check';

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function checkOnStart(array $job, array $opts): array
    {
        $check = BackupReplica::check(true);
        $job['result'] = $check;
        if (!empty($check['ok'])) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('backups.replica.check_ok');
            LongJob::appendLog($job, $job['message']);
        } else {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = (string)($check['error'] ?? t('backups.replica.err.preflight'));
            $job['message'] = $job['error'];
            LongJob::appendLog($job, $job['error']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_CHECK] = $check;
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function checkTick(array $job): array
    {
        // Check completes in on_start; tick is a no-op safety net.
        if (($job['status'] ?? '') === LongJob::STATUS_RUNNING) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
        }
        return $job;
    }

    /**
     * Start / resume domain pull; returns LongJob-shaped public fields (still domain-backed).
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function pullOnStart(array $job, array $opts): array
    {
        $confirm = !empty($job['confirm_wipe']) || !empty($opts['confirm_wipe']);
        $pub = BackupReplica::jobStart($confirm);
        return self::mergePullIntoLongJob($job, $pub);
    }

    /**
     * Session-auth tick (before wipe). Prefer public tick_token endpoint after wipe.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function pullTick(array $job): array
    {
        $wall = (int)round((float)($job['tick_seconds'] ?? BackupReplica::BUDGET_SEC));
        $wall = max(5, min(25, $wall));
        $pub = BackupReplica::jobResumeAndAdvance($wall);
        return self::mergePullIntoLongJob($job, $pub);
    }

    /**
     * Map BackupReplica::publicJob (+ tick_token) → LongJob public/store shape.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $pub
     * @return array<string,mixed>
     */
    public static function mergePullIntoLongJob(array $job, array $pub): array
    {
        $status = (string)($pub['status'] ?? '');
        $progress = is_array($pub['progress'] ?? null) ? $pub['progress'] : [];
        $pct = $progress['pct'] ?? null;
        $overall = is_numeric($pct) ? max(0, min(100, (int)$pct)) : (int)($job['progress_overall'] ?? 0);
        if ($status === 'done') {
            $overall = 100;
        }

        $phase = (string)($pub['phase'] ?? ($progress['phase'] ?? ''));
        $phaseLabel = $phase !== '' ? t('backups.replica.phase.' . $phase) : '';
        if ($phaseLabel === 'backups.replica.phase.' . $phase) {
            $phaseLabel = $phase;
        }
        $mode = (string)($pub['pull_mode'] ?? '');
        $baseline = (string)($pub['baseline_name'] ?? '');
        $msgParts = array_filter([$phaseLabel, $mode !== '' ? $mode : null, $baseline !== '' ? $baseline : null]);
        $message = $msgParts !== [] ? implode(' · ', $msgParts) : (string)($pub['message'] ?? ($job['message'] ?? ''));
        if ($status === 'done' && (string)($pub['message'] ?? '') !== '') {
            $message = (string)$pub['message'];
        }
        if ($status === 'paused' || $status === 'error') {
            $message = (string)($pub['error'] ?? $message);
        }

        $job['id'] = (string)($job['id'] ?? 'replica');
        $job['type'] = self::TYPE_PULL;
        $job['status'] = $status !== '' ? $status : LongJob::STATUS_RUNNING;
        $job['progress'] = $overall;
        $job['progress_overall'] = $overall;
        $job['progress_detail'] = $overall;
        $job['message'] = $message;
        $job['error'] = ($status === 'error' || $status === 'paused')
            ? (string)($pub['error'] ?? '')
            : null;
        $job['phase'] = $phase;
        $job['bytes'] = (int)($pub['download_local'] ?? 0);
        $job['tick_token'] = (string)($pub['tick_token'] ?? ($job['tick_token'] ?? ''));
        $job['relogin'] = !empty($pub['relogin']);
        $job['resumable'] = !empty($pub['resumable']);
        $job['pull_mode'] = $mode;
        $job['baseline_name'] = $baseline;
        $job['result'] = [
            'pull_mode' => $mode,
            'baseline_name' => $baseline,
            'relogin' => !empty($pub['relogin']),
            'resumable' => !empty($pub['resumable']),
            'applied' => $pub['applied'] ?? null,
            'progress' => $progress,
        ];

        if ($status === 'error' && empty($pub['ok']) && (string)($pub['error'] ?? '') !== '' && empty($pub['resumable'])) {
            $job['status'] = LongJob::STATUS_ERROR;
        }
        // Domain "paused" stays as paused for UI resume; LongJob core only auto-ticks "running".
        if ($status === 'done') {
            $job['status'] = LongJob::STATUS_DONE;
        }

        $line = $message !== '' ? $message : $phase;
        if ($line !== '') {
            LongJob::appendLog($job, $line);
        }
        return $job;
    }

    /**
     * Public DTO for UI (includes tick_token / relogin).
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function publicPull(array $job): array
    {
        $pub = LongJob::public($job);
        $pub['tick_token'] = (string)($job['tick_token'] ?? '');
        $pub['relogin'] = !empty($job['relogin']);
        $pub['resumable'] = !empty($job['resumable']);
        $pub['pull_mode'] = (string)($job['pull_mode'] ?? '');
        $pub['baseline_name'] = (string)($job['baseline_name'] ?? '');
        if (isset($job['result']) && is_array($job['result'])) {
            $pub['result'] = $job['result'];
        }
        return $pub;
    }

    /**
     * Shape raw BackupReplica public response for LongJobPanel (token tick path).
     *
     * @param array<string,mixed> $pub
     * @param string $tickToken
     * @return array<string,mixed>
     */
    public static function publicFromDomain(array $pub, string $tickToken = ''): array
    {
        $job = [
            'id' => 'replica',
            'type' => self::TYPE_PULL,
            'log' => [],
            'created_at' => (int)($pub['started_at'] ?? time()),
            'tick_token' => $tickToken !== '' ? $tickToken : (string)($pub['tick_token'] ?? ''),
        ];
        if ($tickToken !== '' && (string)($pub['tick_token'] ?? '') === '') {
            $pub['tick_token'] = $tickToken;
        }
        $job = self::mergePullIntoLongJob($job, $pub);
        return self::publicPull($job);
    }
}
