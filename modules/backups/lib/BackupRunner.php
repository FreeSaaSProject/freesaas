<?php
declare(strict_types=1);

/**
 * Backup state machine advanced by cron ticks (~40s budget).
 *
 * Phases: init → db → cfg → files → pack → encrypt → upload → verify → prune → done
 * Remote artifact: one `{instance}/{stamp}.tar` or `.tar.enc`.
 */
final class BackupRunner
{
    /** Default wall budget per advance() (shared-hosting safe). */
    public const BUDGET_SEC = 40;

    /**
     * Light packs are db+cfg only — keep working continuously (like /db dump), not 8 MiB crumbs.
     * One cron invocation may run up to LIGHT_WALL_SEC of wall time.
     */
    public const BUDGET_SEC_LIGHT = 120;

    /** Continuous wall for light (and manual Continue) — matches ~3–4 min /db dump+pack. */
    public const LIGHT_WALL_SEC = 240;

    /** Fail a truly stuck run so the next schedule can start (slow FTP / huge hard packs). */
    public const MAX_RUN_AGE_SEC = 43200; // 12h

    /** Cron entry: advance running job or start scheduled full/incr / check stale. */
    public static function cronTick(): array
    {
        if (!BackupRepository::tableReady()) {
            return ['ok' => false, 'processed' => 0, 'message' => 'table missing'];
        }

        $running = BackupRepository::findRunning();
        if ($running !== null) {
            $id = (int)$running['id'];
            $started = (int)($running['started_at'] ?? 0);
            if ($started > 0 && (time() - $started) > self::MAX_RUN_AGE_SEC) {
                $phase = (string)($running['phase'] ?? '');
                $msg = t('backups.err.run_stale', [
                    'hours' => (string)max(1, (int)floor((time() - $started) / 3600)),
                    'phase' => $phase !== '' ? $phase : '?',
                ]);
                BackupRepository::markFailed($id, $msg, $phase !== '' ? $phase : 'timeout');
                $failed = BackupRepository::find($id);
                if (is_array($failed)) {
                    BackupNotify::onFail($failed);
                }
                return [
                    'ok' => false,
                    'processed' => 1,
                    'message' => 'stale_run_failed',
                    'run_id' => $id,
                    'phase' => $phase,
                    'status' => 'error',
                    'kind' => (string)($running['kind'] ?? 'full'),
                ];
            }
            $kind = BackupRepository::normalizeKind((string)($running['kind'] ?? 'full'));
            // Light: pump continuously (~/db dump duration), not one 40s crumb per host cron.
            $r = $kind === 'light'
                ? self::advanceUntil($id, self::LIGHT_WALL_SEC)
                : self::advance($id);
            return [
                'ok' => empty($r['error']),
                'processed' => 1,
                'message' => (string)($r['message'] ?? $r['phase'] ?? ''),
                'run_id' => $id,
                'phase' => (string)($r['phase'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
                'kind' => $kind,
            ];
        }

        BackupNotify::checkStale();

        if (!BackupConfig::enabled()) {
            return ['ok' => true, 'processed' => 0, 'message' => 'disabled'];
        }

        // Nightly: prefer weekly hard full; else light (db+cfg); else hourly incr.
        if (self::isHardFullDue()) {
            $id = self::start('cron', 'full');
            $r = self::advance($id);
            return [
                'ok' => empty($r['error']),
                'processed' => 1,
                'message' => 'started_full',
                'run_id' => $id,
                'phase' => (string)($r['phase'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
                'kind' => 'full',
            ];
        }

        if (self::isLightDue()) {
            $id = self::start('cron', 'light');
            $r = self::advance($id);
            return [
                'ok' => empty($r['error']),
                'processed' => 1,
                'message' => 'started_light',
                'run_id' => $id,
                'phase' => (string)($r['phase'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
                'kind' => 'light',
            ];
        }

        if (self::isIncrDue()) {
            $id = self::start('cron', 'incr');
            $r = self::advance($id);
            return [
                'ok' => empty($r['error']),
                'processed' => 1,
                'message' => 'started_incr',
                'run_id' => $id,
                'phase' => (string)($r['phase'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
                'kind' => 'incr',
            ];
        }

        return ['ok' => true, 'processed' => 0, 'message' => 'not due'];
    }

    /** Today’s daily_at timestamp, or false. */
    private static function todayDueTs()
    {
        $at = BackupConfig::dailyAt();
        [$hh, $mm] = array_map('intval', explode(':', $at));
        return mktime($hh, $mm, 0);
    }

    /**
     * Hard full (all file sources): after daily_at, last kind=full older than full_every_days.
     */
    public static function isHardFullDue(): bool
    {
        if (!BackupConfig::hardEnabled()) {
            return false;
        }
        $dueTs = self::todayDueTs();
        if ($dueTs === false || time() < $dueTs) {
            return false;
        }
        $lastFull = BackupRepository::findLastSuccessKind('full');
        $lastAt = $lastFull !== null
            ? (int)($lastFull['finished_at'] ?? $lastFull['started_at'] ?? 0)
            : 0;
        // lastFullAt may be from light — only count hard fulls for weekly cadence
        $minAge = BackupConfig::fullEveryDays() * 86400;
        if ($lastAt > 0 && (time() - $lastAt) < $minAge) {
            return false;
        }
        $last = BackupRepository::findLast();
        if ($last !== null && ($last['kind'] ?? '') === 'full') {
            $started = (int)($last['started_at'] ?? 0);
            $st = (string)($last['status'] ?? '');
            if ($started >= $dueTs && $st !== 'done') {
                return false;
            }
        }
        return true;
    }

    /**
     * Nightly light (db+cfg only): after daily_at, once per night unless hard full already covers tonight.
     */
    public static function isLightDue(): bool
    {
        if (!BackupConfig::lightNightly()) {
            return false;
        }
        $dueTs = self::todayDueTs();
        if ($dueTs === false || time() < $dueTs) {
            return false;
        }
        // Already have a DB baseline (light or hard) started/finished after today's daily_at
        $base = BackupRepository::findLastSuccessBaseline();
        if ($base !== null) {
            $fin = (int)($base['finished_at'] ?? 0);
            $start = (int)($base['started_at'] ?? 0);
            if (max($fin, $start) >= $dueTs) {
                return false;
            }
        }
        $last = BackupRepository::findLast();
        if ($last !== null) {
            $k = (string)($last['kind'] ?? '');
            if ($k === 'light' || $k === 'full') {
                $started = (int)($last['started_at'] ?? 0);
                $st = (string)($last['status'] ?? '');
                if ($started >= $dueTs && $st !== 'done') {
                    return false;
                }
            }
        }
        return true;
    }

    /** @deprecated use isHardFullDue / isLightDue */
    public static function isFullDue(): bool
    {
        return self::isHardFullDue();
    }

    /** @deprecated */
    public static function isDueToday(): bool
    {
        return self::isHardFullDue() || self::isLightDue();
    }

    public static function isIncrDue(): bool
    {
        if (!BackupConfig::cdcEnabled() || BackupConfig::incrEveryHours() < 1) {
            return false;
        }
        if (!BackupCdc::journalReady()) {
            return false;
        }
        $cursor = BackupConfig::incrCursor();
        $maxId = BackupCdc::maxJournalId();
        if ($maxId <= $cursor) {
            return false;
        }
        $lastIncr = BackupRepository::findLastSuccessKind('incr');
        $lastAt = $lastIncr !== null ? (int)($lastIncr['finished_at'] ?? 0) : 0;
        $every = BackupConfig::incrEveryHours() * 3600;
        if ($lastAt > 0 && (time() - $lastAt) < $every) {
            return false;
        }
        // Need a DB baseline (light or hard) to chain from
        if (BackupConfig::lastFullRemote() === '' && BackupRepository::findLastSuccessBaseline() === null) {
            return false;
        }
        return true;
    }

    public static function start(string $triggeredBy = 'manual', string $kind = 'full'): int
    {
        $kind = BackupRepository::normalizeKind($kind);
        $running = BackupRepository::findRunning();
        if ($running !== null) {
            return (int)$running['id'];
        }
        $ftp = BackupConfig::ftpConfig();
        if (trim($ftp['host']) === '') {
            throw new RuntimeException(t('backups.err.ftp_host'));
        }
        if (BackupConfig::encryptEnabled() && BackupConfig::passphrase() === '') {
            throw new RuntimeException(t('backups.err.passphrase'));
        }
        $free = @disk_free_space(BackupConfig::workDir());
        if (is_int($free) || is_float($free)) {
            if ((int)$free < 100 * 1024 * 1024) {
                throw new RuntimeException(t('backups.err.disk_space'));
            }
        }
        $id = BackupRepository::start($triggeredBy, $kind);
        $stamp = date('Y-m-d_H-i-s');
        $instance = BackupConfig::instance();
        $localDir = BackupConfig::workDir() . '/run-' . $id;
        if (!is_dir($localDir)) {
            @mkdir($localDir, 0755, true);
        }
        $incrMode = BackupConfig::incrMode();
        $incrSeq = 0;
        if ($kind === 'incr') {
            $incrSeq = BackupConfig::incrSeq() + 1;
            $packName = BackupFtpTarget::cdcPackName($stamp, $incrMode, $incrSeq, false);
        } elseif ($kind === 'light') {
            $packName = $stamp . '.light.tar';
        } else {
            $packName = $stamp . '.tar';
        }
        $state = [
            'kind' => $kind,
            'stamp' => $stamp,
            'instance' => $instance,
            'local_dir' => $localDir,
            'pack_name' => $packName,
            'remote_file' => $instance . '/' . $packName,
            'remote_dir' => $instance . '/' . $packName,
            'encrypt' => BackupConfig::encryptEnabled(),
            'artifacts' => [],
            'phase_detail' => [],
        ];
        if ($kind === 'incr') {
            $state['incr_mode'] = $incrMode;
            $state['incr_seq'] = $incrSeq;
            $state['cursor_to'] = BackupCdc::maxJournalId();
            if ($incrMode === 'differential') {
                $state['cursor_from'] = BackupConfig::incrBaselineCursor();
            } else {
                $state['cursor_from'] = BackupConfig::incrCursor();
            }
            $base = BackupRepository::findLastSuccessBaseline();
            $state['base_full'] = BackupConfig::lastFullRemote();
            if ($state['base_full'] === '' && $base !== null) {
                $state['base_full'] = (string)($base['remote_dir'] ?? '');
            }
        }
        BackupRepository::update($id, 'init', $state, 0, (string)$state['remote_file']);
        return $id;
    }

    /**
     * Keep advancing a run until done/error or wall-clock budget (manual «Run now»).
     *
     * @return array<string,mixed>
     */
    public static function advanceUntil(int $id, int $wallSec = 50): array
    {
        $wallSec = max(5, min(300, $wallSec));
        $t0 = microtime(true);
        $r = ['status' => 'running'];
        while ((microtime(true) - $t0) < $wallSec) {
            $r = self::advance($id);
            $st = (string)($r['status'] ?? '');
            if ($st !== 'running') {
                return $r;
            }
            // Yield briefly so long dumps don't look wedged to the OS.
            usleep(20000);
        }
        return $r;
    }

    /** @return array<string,mixed> */
    public static function advance(int $id): array
    {
        $run = BackupRepository::find($id);
        if ($run === null) {
            return ['error' => 'not found', 'status' => 'error'];
        }
        if (($run['status'] ?? '') !== 'running') {
            return $run;
        }
        $state = is_array($run['state'] ?? null) ? $run['state'] : [];
        $phase = (string)($run['phase'] ?? 'init');
        $kind = BackupRepository::normalizeKind((string)($state['kind'] ?? $run['kind'] ?? 'full'));
        $state['kind'] = $kind;
        try {
            $budget = $kind === 'light' ? self::BUDGET_SEC_LIGHT : self::BUDGET_SEC;
            $t0 = microtime(true);
            while ((microtime(true) - $t0) < $budget) {
                if ($kind === 'incr') {
                    if ($phase === 'init' || $phase === '') {
                        $phase = 'incr_sql';
                        $state['phase_detail'] = [];
                    } elseif ($phase === 'incr_sql') {
                        $r = self::phaseIncrSql($state);
                        $state = $r['state'];
                        $phase = 'pack';
                        $state['phase_detail'] = [];
                    } elseif ($phase === 'pack') {
                        $r = self::phasePack($state, $budget - (microtime(true) - $t0));
                        $state = $r['state'];
                        if (!empty($r['done'])) {
                            $phase = !empty($state['encrypt']) ? 'encrypt' : 'upload';
                            $state['phase_detail'] = [];
                        } else {
                            break;
                        }
                    } elseif ($phase === 'encrypt') {
                        $r = self::phaseEncryptPack($state, $budget - (microtime(true) - $t0));
                        $state = $r['state'];
                        if (!empty($r['done'])) {
                            $phase = 'upload';
                            $state['phase_detail'] = [];
                        } else {
                            break;
                        }
                    } elseif ($phase === 'upload') {
                        $r = self::phaseUploadPack($state, $budget - (microtime(true) - $t0));
                        $state = $r['state'];
                        if (!empty($r['done'])) {
                            $phase = 'verify';
                            $state['phase_detail'] = [];
                        } else {
                            break;
                        }
                    } elseif ($phase === 'verify') {
                        $r = self::phaseVerifyPack($state);
                        $state = $r['state'];
                        $phase = 'prune';
                        $state['phase_detail'] = [];
                    } elseif ($phase === 'prune') {
                        self::phasePrune($state);
                        $bytes = (int)($state['pack_bytes'] ?? self::totalBytes($state));
                        $remote = (string)($state['remote_file'] ?? $state['remote_dir'] ?? '');
                        BackupRepository::markDone($id, $bytes, $remote);
                        BackupConfig::setIncrCursor((int)($state['cursor_to'] ?? BackupConfig::incrCursor()));
                        BackupConfig::setIncrSeq((int)($state['incr_seq'] ?? BackupConfig::incrSeq()));
                        $done = BackupRepository::find($id);
                        if (is_array($done)) {
                            BackupNotify::onSuccess($done);
                        }
                        self::cleanupLocal($state);
                        return $done ?? ['status' => 'done', 'phase' => 'done', 'kind' => 'incr'];
                    } else {
                        break;
                    }
                    BackupRepository::update(
                        $id,
                        $phase,
                        $state,
                        (int)($state['pack_bytes'] ?? self::totalBytes($state)),
                        (string)($state['remote_file'] ?? $state['remote_dir'] ?? '')
                    );
                    continue;
                }

                if ($phase === 'init' || $phase === '') {
                    $phase = 'db';
                    $state['phase_detail'] = [];
                } elseif ($phase === 'db') {
                    $r = self::phaseDb($state, $budget - (microtime(true) - $t0));
                    $state = $r['state'];
                    if (!empty($r['done'])) {
                        $phase = 'cfg';
                        $state['phase_detail'] = [];
                    } else {
                        break;
                    }
                } elseif ($phase === 'cfg') {
                    $r = self::phaseCfg($state, $budget - (microtime(true) - $t0));
                    $state = $r['state'];
                    if (!empty($r['done'])) {
                        $phase = 'files';
                        $state['phase_detail'] = [];
                        $state['files_queue'] = self::filesQueue($state);
                        $state['files_idx'] = 0;
                    } else {
                        break;
                    }
                } elseif ($phase === 'files') {
                    $r = self::phaseFiles($state, $budget - (microtime(true) - $t0));
                    $state = $r['state'];
                    if (!empty($r['done'])) {
                        $phase = 'pack';
                        $state['phase_detail'] = [];
                    } else {
                        break;
                    }
                } elseif ($phase === 'pack') {
                    $r = self::phasePack($state, $budget - (microtime(true) - $t0));
                    $state = $r['state'];
                    if (!empty($r['done'])) {
                        $phase = !empty($state['encrypt']) ? 'encrypt' : 'upload';
                        $state['phase_detail'] = [];
                    } else {
                        break;
                    }
                } elseif ($phase === 'encrypt') {
                    $r = self::phaseEncryptPack($state, $budget - (microtime(true) - $t0));
                    $state = $r['state'];
                    if (!empty($r['done'])) {
                        $phase = 'upload';
                        $state['phase_detail'] = [];
                    } else {
                        break;
                    }
                } elseif ($phase === 'upload') {
                    $r = self::phaseUploadPack($state, $budget - (microtime(true) - $t0));
                    $state = $r['state'];
                    if (!empty($r['done'])) {
                        $phase = 'verify';
                        $state['phase_detail'] = [];
                    } else {
                        break;
                    }
                } elseif ($phase === 'verify') {
                    $r = self::phaseVerifyPack($state);
                    $state = $r['state'];
                    $phase = 'prune';
                    $state['phase_detail'] = [];
                } elseif ($phase === 'prune') {
                    self::phasePrune($state);
                    $bytes = (int)($state['pack_bytes'] ?? self::totalBytes($state));
                    $remote = (string)($state['remote_file'] ?? $state['remote_dir'] ?? '');
                    BackupRepository::markDone($id, $bytes, $remote);
                    BackupConfig::setLastFullRemote($remote);
                    BackupConfig::setLastFullAt(time());
                    // Baseline = CDC watermark taken at dump start (not current MAX after upload).
                    // Prune only ≤ watermark so mid-dump/mid-upload journal rows survive into the next CDC pack.
                    self::commitCdcBaselineAfterLightOrFull($state);
                    $done = BackupRepository::find($id);
                    if (is_array($done)) {
                        BackupNotify::onSuccess($done);
                    }
                    self::cleanupLocal($state);
                    return $done ?? ['status' => 'done', 'phase' => 'done', 'kind' => $kind];
                } else {
                    break;
                }
                BackupRepository::update(
                    $id,
                    $phase,
                    $state,
                    (int)($state['pack_bytes'] ?? self::totalBytes($state)),
                    (string)($state['remote_file'] ?? $state['remote_dir'] ?? '')
                );
            }
            BackupRepository::update(
                $id,
                $phase,
                $state,
                (int)($state['pack_bytes'] ?? self::totalBytes($state)),
                (string)($state['remote_file'] ?? $state['remote_dir'] ?? '')
            );
            $out = BackupRepository::find($id);
            return $out ?? ['status' => 'running', 'phase' => $phase];
        } catch (Throwable $e) {
            BackupRepository::markFailed($id, $e->getMessage(), $phase);
            $failed = BackupRepository::find($id);
            if (is_array($failed)) {
                BackupNotify::onFail($failed);
            }
            return $failed ?? ['status' => 'error', 'error' => $e->getMessage(), 'phase' => $phase];
        }
    }

    /**
     * Build incr.sql.gz from CDC journal between cursor_from and cursor_to.
     *
     * @param array<string,mixed> $state
     * @return array{done:bool,state:array<string,mixed>}
     */
    private static function phaseIncrSql(array $state): array
    {
        $from = (int)($state['cursor_from'] ?? 0);
        $to = (int)($state['cursor_to'] ?? 0);
        if ($to <= $from) {
            throw new RuntimeException(t('backups.err.incr_empty'));
        }
        $changes = BackupCdc::collapsedChanges($from, $to);
        $localDir = (string)$state['local_dir'];
        $sqlPath = $localDir . '/incr.sql';
        $stats = BackupCdc::writeIncrSql($sqlPath, $changes);
        $gzPath = $sqlPath . '.gz';
        $raw = (string)file_get_contents($sqlPath);
        $gz = gzencode($raw, 6);
        if ($gz === false || file_put_contents($gzPath, $gz) === false) {
            throw new RuntimeException('incr gzip failed');
        }
        @unlink($sqlPath);
        $arts = is_array($state['artifacts'] ?? null) ? $state['artifacts'] : [];
        $arts[] = [
            'name' => 'incr.sql.gz',
            'path' => $gzPath,
            'sha256' => hash_file('sha256', $gzPath),
            'bytes' => (int)filesize($gzPath),
        ];
        $state['artifacts'] = $arts;
        $state['incr_stats'] = $stats;
        $state['phase_detail'] = [];
        return ['done' => true, 'state' => $state];
    }

    /** @param array<string,mixed> $state @return array{done:bool,state:array<string,mixed>} */
    private static function phaseDb(array $state, float $budgetLeft): array
    {
        $detail = is_array($state['phase_detail'] ?? null) ? $state['phase_detail'] : [];
        $jobId = (string)($detail['job_id'] ?? '');
        if ($jobId === '') {
            return self::phaseDbStartDump($state, $detail);
        }
        $t0 = microtime(true);
        $job = null;
        try {
            while ((microtime(true) - $t0) < max(5.0, $budgetLeft)) {
                $job = DbJobs::jobTick($jobId);
                $st = (string)($job['status'] ?? '');
                if ($st !== 'running') {
                    break;
                }
            }
            if ($job === null) {
                $job = DbJobs::jobGet($jobId);
            }
        } catch (Throwable $e) {
            // var/db_jobs/{id}.json lost (host cleanup, disk, crash) while backup run still "running".
            $msg = $e->getMessage();
            $restarts = (int)($detail['dump_restarts'] ?? 0);
            if ($restarts < 1 && (stripos($msg, 'Job not found') !== false || stripos($msg, 'Corrupt job') !== false)) {
                $detail = ['dump_restarts' => $restarts + 1];
                // New dump attempt → new consistency watermark.
                unset($state['cdc_watermark']);
                return self::phaseDbStartDump($state, $detail);
            }
            throw new RuntimeException(
                t('backups.err.dump_job_lost', ['id' => $jobId, 'msg' => $msg]),
                0,
                $e
            );
        }
        $st = (string)($job['status'] ?? '');
        if ($st === 'error' || $st === 'cancelled') {
            throw new RuntimeException((string)($job['error'] ?? $job['message'] ?? 'dump failed'));
        }
        if ($st !== 'done') {
            $state['phase_detail'] = $detail;
            return ['done' => false, 'state' => $state];
        }
        $file = (string)($job['file'] ?? '');
        $src = DbFs::resolveDumpPath($file);
        $localDir = (string)$state['local_dir'];
        $destName = 'db.sql.gz';
        $dest = $localDir . '/' . $destName;
        if (!@copy($src, $dest)) {
            throw new RuntimeException('Cannot copy dump to work dir');
        }
        $arts = is_array($state['artifacts'] ?? null) ? $state['artifacts'] : [];
        $arts[] = ['name' => $destName, 'path' => $dest, 'sha256' => hash_file('sha256', $dest), 'bytes' => (int)filesize($dest)];
        $state['artifacts'] = $arts;
        $state['phase_detail'] = [];
        return ['done' => true, 'state' => $state];
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $detail
     * @return array{done:bool,state:array<string,mixed>}
     */
    private static function phaseDbStartDump(array $state, array $detail): array
    {
        $tables = BackupConfig::dbScope() === 'all'
            ? DbTools::allTables()
            : DbTools::ownedTables();
        if ($tables === []) {
            throw new RuntimeException(t('backups.err.no_tables'));
        }
        // Consistency point for CDC: changes after this ID are not guaranteed in the dump
        // (table already exported) and must remain in the journal for the next CDC pack.
        if (BackupCdc::journalReady() && (int)($state['cdc_watermark'] ?? 0) < 1) {
            $state['cdc_watermark'] = BackupCdc::maxJournalId();
        }
        $job = DbJobs::jobStart('dump', [
            'tables' => $tables,
            'label' => 'backup-' . (string)($state['stamp'] ?? ''),
        ]);
        $detail['job_id'] = (string)$job['id'];
        $state['phase_detail'] = $detail;
        return ['done' => false, 'state' => $state];
    }

    /**
     * After light/full success: freeze CDC baseline at dump-start watermark and prune only that prefix.
     *
     * @param array<string,mixed> $state
     */
    private static function commitCdcBaselineAfterLightOrFull(array $state): void
    {
        if (!BackupCdc::journalReady()) {
            BackupConfig::resetIncrAfterBaseline(BackupConfig::incrCursor());
            return;
        }
        $wm = (int)($state['cdc_watermark'] ?? 0);
        if ($wm < 1) {
            // In-flight / legacy run without watermark: do not prune — keep journal for next CDC pack.
            // Baseline 0 makes the next differential re-export remaining journal (REPLACE-safe).
            BackupConfig::resetIncrAfterBaseline(0);
            return;
        }
        BackupConfig::resetIncrAfterBaseline($wm);
        BackupCdc::pruneJournal($wm);
    }

    /** @param array<string,mixed> $state @return array{done:bool,state:array<string,mixed>} */
    private static function phaseCfg(array $state, float $budgetLeft): array
    {
        $selected = false;
        foreach (BackupSources::selected() as $s) {
            if ($s['id'] === 'cfg' || $s['kind'] === 'cfg') {
                $selected = true;
                break;
            }
        }
        if (!$selected) {
            return ['done' => true, 'state' => $state];
        }
        $detail = is_array($state['phase_detail'] ?? null) ? $state['phase_detail'] : [];
        $localDir = (string)$state['local_dir'];
        $out = $localDir . '/cfg.tar';
        // isset: empty inventory [] must not re-scan forever (empty() is true for []).
        if (!isset($detail['files'])) {
            $base = rtrim(base_path(), '/');
            $paths = [$base . '/cfg'];
            $files = BackupTarWriter::inventory($paths, BackupSources::globalExcludes());
            $detail['files'] = $files;
            $detail['tar'] = [];
            $state['phase_detail'] = $detail;
        }
        // cfg is tiny — burn remaining budget in one go (should be <1s).
        $r = BackupTarWriter::tick(
            $out,
            is_array($detail['files']) ? $detail['files'] : [],
            is_array($detail['tar'] ?? null) ? $detail['tar'] : [],
            (int)max(15, $budgetLeft)
        );
        $detail['tar'] = $r['state'];
        $state['phase_detail'] = $detail;
        if (empty($r['done'])) {
            return ['done' => false, 'state' => $state];
        }
        $arts = is_array($state['artifacts'] ?? null) ? $state['artifacts'] : [];
        $arts[] = [
            'name' => 'cfg.tar',
            'path' => $out,
            'sha256' => hash_file('sha256', $out),
            'bytes' => (int)filesize($out),
        ];
        $state['artifacts'] = $arts;
        $state['phase_detail'] = [];
        return ['done' => true, 'state' => $state];
    }

    /** @param array<string,mixed> $state @return list<array{id:string,paths:list<string>,excludes:list<string>}> */
    private static function filesQueue(array $state): array
    {
        // Nightly light = db + cfg only (no media / userfiles).
        if (($state['kind'] ?? '') === 'light') {
            return [];
        }
        $q = [];
        foreach (BackupSources::selected() as $s) {
            if (($s['kind'] ?? '') !== 'files') {
                continue;
            }
            $q[] = [
                'id' => $s['id'],
                'paths' => $s['paths'],
                'excludes' => array_merge($s['excludes'], BackupSources::globalExcludes()),
            ];
        }
        return $q;
    }

    /** @param array<string,mixed> $state @return array{done:bool,state:array<string,mixed>} */
    private static function phaseFiles(array $state, float $budgetLeft): array
    {
        $queue = is_array($state['files_queue'] ?? null) ? $state['files_queue'] : [];
        $idx = (int)($state['files_idx'] ?? 0);
        if ($idx >= count($queue)) {
            return ['done' => true, 'state' => $state];
        }
        $item = $queue[$idx];
        $id = (string)($item['id'] ?? 'files');
        $localDir = (string)$state['local_dir'];
        $out = $localDir . '/files-' . $id . '.tar';
        $detail = is_array($state['phase_detail'] ?? null) ? $state['phase_detail'] : [];
        if (!isset($detail['files'])) {
            $detail['files'] = BackupTarWriter::inventory(
                is_array($item['paths'] ?? null) ? $item['paths'] : [],
                is_array($item['excludes'] ?? null) ? $item['excludes'] : []
            );
            $detail['tar'] = [];
        }
        $r = BackupTarWriter::tick(
            $out,
            is_array($detail['files']) ? $detail['files'] : [],
            is_array($detail['tar'] ?? null) ? $detail['tar'] : [],
            (int)max(5, $budgetLeft)
        );
        $detail['tar'] = $r['state'];
        $state['phase_detail'] = $detail;
        if (empty($r['done'])) {
            return ['done' => false, 'state' => $state];
        }
        $arts = is_array($state['artifacts'] ?? null) ? $state['artifacts'] : [];
        $arts[] = [
            'name' => 'files-' . $id . '.tar',
            'path' => $out,
            'sha256' => hash_file('sha256', $out),
            'bytes' => (int)filesize($out),
        ];
        $state['artifacts'] = $arts;
        $state['files_idx'] = $idx + 1;
        $state['phase_detail'] = [];
        return ['done' => $state['files_idx'] >= count($queue), 'state' => $state];
    }

    /**
     * Write manifest.json then pack all artifacts into one `{stamp}.tar`.
     *
     * @param array<string,mixed> $state
     * @return array{done:bool,state:array<string,mixed>}
     */
    private static function phasePack(array $state, float $budgetLeft): array
    {
        $localDir = (string)$state['local_dir'];
        $packName = (string)($state['pack_name'] ?? ((string)$state['stamp'] . '.tar'));
        $packPath = $localDir . '/' . $packName;
        $detail = is_array($state['phase_detail'] ?? null) ? $state['phase_detail'] : [];

        if (empty($detail['manifest_written'])) {
            $manifest = self::buildManifest($state);
            $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('manifest encode failed');
            }
            $manPath = $localDir . '/manifest.json';
            if (file_put_contents($manPath, $json) === false) {
                throw new RuntimeException('Cannot write manifest.json');
            }
            $detail['manifest_written'] = 1;
            $detail['tar_files'] = self::packFileList($state, $manPath);
            $detail['tar'] = [];
            $state['phase_detail'] = $detail;
            return ['done' => false, 'state' => $state];
        }

        $r = BackupTarWriter::tick(
            $packPath,
            is_array($detail['tar_files'] ?? null) ? $detail['tar_files'] : [],
            is_array($detail['tar'] ?? null) ? $detail['tar'] : [],
            (int)max(5, $budgetLeft)
        );
        $detail['tar'] = $r['state'];
        $state['phase_detail'] = $detail;
        if (empty($r['done'])) {
            return ['done' => false, 'state' => $state];
        }

        $bytes = (int)filesize($packPath);
        $state['pack_path'] = $packPath;
        $state['pack_name'] = $packName;
        $state['pack_bytes'] = $bytes;
        $state['pack_sha256'] = hash_file('sha256', $packPath);
        $state['remote_file'] = (string)$state['instance'] . '/' . $packName;
        $state['remote_dir'] = $state['remote_file'];
        $state['phase_detail'] = [];
        return ['done' => true, 'state' => $state];
    }

    /**
     * @param array<string,mixed> $state
     * @return list<array{path:string,name:string,size:int,mtime:int}>
     */
    private static function packFileList(array $state, string $manifestPath): array
    {
        $files = [];
        foreach ((array)($state['artifacts'] ?? []) as $a) {
            if (!is_array($a)) {
                continue;
            }
            $path = (string)($a['path'] ?? '');
            $name = (string)($a['name'] ?? '');
            if ($path === '' || $name === '' || !is_file($path)) {
                continue;
            }
            $files[] = [
                'path' => $path,
                'name' => $name,
                'size' => (int)filesize($path),
                'mtime' => (int)filemtime($path),
            ];
        }
        $files[] = [
            'path' => $manifestPath,
            'name' => 'manifest.json',
            'size' => (int)filesize($manifestPath),
            'mtime' => (int)filemtime($manifestPath),
        ];
        return $files;
    }

    /** @param array<string,mixed> $state @return array{done:bool,state:array<string,mixed>} */
    private static function phaseEncryptPack(array $state, float $budgetLeft): array
    {
        $src = (string)($state['pack_path'] ?? '');
        if ($src === '' || !is_file($src)) {
            throw new RuntimeException('Pack missing before encrypt');
        }
        $dest = $src . '.enc';
        $detail = is_array($state['phase_detail'] ?? null) ? $state['phase_detail'] : [];
        $r = BackupCrypto::encryptTick(
            $src,
            $dest,
            BackupConfig::passphrase(),
            is_array($detail['crypto'] ?? null) ? $detail['crypto'] : [],
            (int)max(5, $budgetLeft)
        );
        $detail['crypto'] = $r['state'];
        $state['phase_detail'] = $detail;
        if (empty($r['done'])) {
            return ['done' => false, 'state' => $state];
        }
        @unlink($src);
        $name = basename($dest);
        $state['pack_path'] = $dest;
        $state['pack_name'] = $name;
        $state['pack_bytes'] = (int)filesize($dest);
        $state['pack_sha256'] = hash_file('sha256', $dest);
        $state['remote_file'] = (string)$state['instance'] . '/' . $name;
        $state['remote_dir'] = $state['remote_file'];
        $state['phase_detail'] = [];
        return ['done' => true, 'state' => $state];
    }

    /** @param array<string,mixed> $state @return array{done:bool,state:array<string,mixed>} */
    private static function phaseUploadPack(array $state, float $budgetLeft): array
    {
        $local = (string)($state['pack_path'] ?? '');
        $remote = (string)($state['remote_file'] ?? '');
        if ($local === '' || !is_file($local) || $remote === '') {
            throw new RuntimeException('Nothing to upload');
        }
        $target = BackupFtpTarget::fromConfig();
        try {
            $target->ensureBaseAndInstance((string)$state['instance']);
            $detail = is_array($state['phase_detail'] ?? null) ? $state['phase_detail'] : [];
            $r = $target->putAtomic(
                $local,
                $remote,
                is_array($detail['put'] ?? null) ? $detail['put'] : [],
                (int)max(5, $budgetLeft)
            );
            $detail['put'] = $r['state'];
            $state['phase_detail'] = $detail;
            if (empty($r['done'])) {
                return ['done' => false, 'state' => $state];
            }
            $state['phase_detail'] = [];
            return ['done' => true, 'state' => $state];
        } finally {
            $target->close();
        }
    }

    /** @param array<string,mixed> $state @return array{done:bool,state:array<string,mixed>} */
    private static function phaseVerifyPack(array $state): array
    {
        $target = BackupFtpTarget::fromConfig();
        try {
            $remote = (string)($state['remote_file'] ?? '');
            $expect = (int)($state['pack_bytes'] ?? 0);
            $sz = $target->size($remote);
            if ($sz !== $expect) {
                throw new RuntimeException(t('backups.err.verify_size', [
                    'file' => basename($remote),
                    'expect' => (string)$expect,
                    'got' => (string)$sz,
                ]));
            }
            return ['done' => true, 'state' => $state];
        } finally {
            $target->close();
        }
    }

    /** @param array<string,mixed> $state */
    private static function phasePrune(array $state): void
    {
        $target = BackupFtpTarget::fromConfig();
        try {
            $instance = (string)($state['instance'] ?? BackupConfig::instance());
            $kind = (string)($state['kind'] ?? 'full');
            if ($kind === 'incr') {
                $target->pruneIncr($instance, BackupConfig::keepIncr());
            } elseif ($kind === 'light') {
                $target->pruneLight($instance, BackupConfig::keepLight());
            } else {
                $target->prune($instance, BackupConfig::keepRemote());
            }
        } finally {
            $target->close();
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function buildManifest(array $state): array
    {
        $modules = [];
        foreach (ModuleManager::all() as $id => $meta) {
            if (!ModuleManager::isEnabled((string)$id)) {
                continue;
            }
            $modules[(string)$id] = (string)($meta['version'] ?? '');
        }
        $files = [];
        foreach ((array)($state['artifacts'] ?? []) as $a) {
            if (!is_array($a)) {
                continue;
            }
            $files[] = [
                'name' => (string)$a['name'],
                'bytes' => (int)($a['bytes'] ?? 0),
                'sha256' => (string)($a['sha256'] ?? ''),
                'encrypted' => !empty($a['encrypted']),
            ];
        }
        $kind = (string)($state['kind'] ?? 'full');
        $manifest = [
            'format' => $kind === 'incr' ? 2 : ($kind === 'light' ? 3 : 1),
            'kind' => $kind,
            'created_at' => date('c'),
            'instance' => (string)($state['instance'] ?? ''),
            'stamp' => (string)($state['stamp'] ?? ''),
            'core_version' => (string)(ModuleManager::all()['core']['version'] ?? ''),
            'modules' => $modules,
            'encrypt' => !empty($state['encrypt']),
            'db_scope' => BackupConfig::dbScope(),
            'files' => $files,
        ];
        if ($kind === 'incr') {
            $manifest['base_full'] = (string)($state['base_full'] ?? '');
            $manifest['cursor_from'] = (int)($state['cursor_from'] ?? 0);
            $manifest['cursor_to'] = (int)($state['cursor_to'] ?? 0);
            $manifest['incr_mode'] = (string)($state['incr_mode'] ?? BackupConfig::incrMode());
            $manifest['incr_seq'] = (int)($state['incr_seq'] ?? 0);
            $manifest['incr_stats'] = is_array($state['incr_stats'] ?? null) ? $state['incr_stats'] : [];
            // Schema fingerprint for standby
            try {
                $mig = Database::pdo()->query(
                    'SELECT module_id, migration_id FROM base_schema_migrations ORDER BY module_id, migration_id'
                )->fetchAll(PDO::FETCH_ASSOC);
                $manifest['schema_migrations'] = $mig;
            } catch (Throwable $e) {
                $manifest['schema_migrations'] = [];
            }
        }
        return $manifest;
    }

    /** @param array<string,mixed> $state */
    private static function totalBytes(array $state): int
    {
        $n = 0;
        foreach ((array)($state['artifacts'] ?? []) as $a) {
            if (is_array($a)) {
                $n += (int)($a['bytes'] ?? 0);
            }
        }
        return $n;
    }

    /** @param array<string,mixed> $state */
    private static function cleanupLocal(array $state): void
    {
        $keep = BackupConfig::keepLocal();
        $dir = (string)($state['local_dir'] ?? '');
        if ($keep <= 0 && $dir !== '' && is_dir($dir)) {
            self::rmTree($dir);
        }
        // prune old local run-* dirs
        $root = BackupConfig::workDir();
        $dirs = glob($root . '/run-*') ?: [];
        rsort($dirs, SORT_STRING);
        $i = 0;
        foreach ($dirs as $d) {
            if (!is_dir($d)) {
                continue;
            }
            $i++;
            if ($i > $keep) {
                self::rmTree($d);
            }
        }
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $info) {
            /** @var SplFileInfo $info */
            $p = $info->getPathname();
            if ($info->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }
}
