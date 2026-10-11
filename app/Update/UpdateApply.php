<?php
declare(strict_types=1);

/** Update engine: apply + progressive jobs + fail/rollback. */
final class UpdateApply
{
    public const JOB_STEPS = [
        'resolve',
        'download',
        'verify',
        'extract',
        'overlay',
        'migrate',
        'health',
        'finalize',
    ];

    /**
     * @return array{ok:bool,results:list,http:int,error?:string}
     */
    public static function apply(?string $target = null, bool $migrate = true, bool $dbBackup = false): array
    {
        $lock = UpdateFs::acquireLock();
        if ($lock === null) {
            return ['ok' => false, 'error' => 'Update already in progress (lock)', 'results' => [], 'http' => 409];
        }
        try {
            $onlyNewer = $target === null || trim((string)$target) === '' || strtolower(trim((string)$target)) === 'all';
            $packages = UpdateCheck::resolvePackages($target, $onlyNewer);
            if ($packages === []) {
                return [
                    'ok' => false,
                    'error' => $onlyNewer
                        ? 'Nothing to apply (no newer packages). Run Check first.'
                        : 'Unknown or empty update target',
                    'results' => [],
                    'http' => 400,
                ];
            }
            $dbBackup = $dbBackup || UpdateConfig::dbBackupBeforeMigrate();
            $results = [];
            $archiveCache = [];
            foreach ($packages as $p) {
                $one = UpdateApply::applyOne($p, $migrate, $archiveCache, $dbBackup);
                $results[] = $one;
                if (empty($one['ok'])) {
                    return ['ok' => false, 'results' => $results, 'http' => 500];
                }
            }
            return ['ok' => true, 'results' => $results, 'http' => 200];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'results' => [], 'http' => 400];
        } finally {
            UpdateFs::releaseLock($lock);
        }
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,array{zip:string,stage:string}> $archiveCache
     * @return array<string,mixed>
     */
    public static function applyOne(array $source, bool $migrate, array &$archiveCache, bool $dbBackup = false): array
    {
        $id = (string)$source['id'];
        $snapshotId = '';
        $dumpFile = '';
        $failPhase = '';
        try {
            $remote = isset($source['_remote']) && is_array($source['_remote'])
                ? $source['_remote']
                : UpdateGithub::resolveRemoteMeta($source);

            $cacheKey = ((string)($source['bundle'] ?? '')) . '|' . ((string)($source['repo'] ?? '')) . '|' . ((string)($remote['ref'] ?? '')) . '|' . ((string)($remote['url'] ?? ''));
            if (!isset($archiveCache[$cacheKey])) {
                UpdateFs::ensureDir(UpdateFs::workDir());
                $stamp = gmdate('YmdHis') . '-' . substr(sha1($cacheKey), 0, 8);
                $zipPath = UpdateFs::workDir() . '/' . $stamp . '.zip';
                $stageDir = UpdateFs::workDir() . '/' . $stamp . '-stage';
                UpdateGithub::downloadFile((string)$remote['url'], $zipPath, (string)$source['token']);
                $sha = (string)($remote['sha256'] ?? '');
                if ($sha !== '') {
                    $got = hash_file('sha256', $zipPath);
                    if (!is_string($got) || !hash_equals(strtolower($sha), strtolower($got))) {
                        throw new RuntimeException('SHA-256 mismatch for ' . $id);
                    }
                }
                UpdateOverlay::extractZip($zipPath, $stageDir);
                $archiveCache[$cacheKey] = ['zip' => $zipPath, 'stage' => $stageDir];
            }
            $stageDir = $archiveCache[$cacheKey]['stage'];
            $payloadRoot = UpdateOverlay::findPayloadRoot(
                $stageDir,
                (string)($source['path_prefix'] ?? ''),
                (string)$source['kind'],
                $id
            );
            $kind = (string)$source['kind'];
            $bundle = (string)($source['bundle'] ?? '');
            $paths = UpdateOverlay::collectOverlayPaths($payloadRoot, $kind, $id, $bundle);
            $snap = UpdateRollback::snapshotBeforeOverlay($paths, $id);
            $snapshotId = (string)$snap['id'];

            $failPhase = 'overlay';
            $copied = UpdateOverlay::overlay($payloadRoot, $kind, $id, $bundle);

            if ($migrate && $dbBackup) {
                $dump = AgentOps::dbDump('update-pre-' . $id);
                if (empty($dump['ok'])) {
                    throw new RuntimeException((string)($dump['error'] ?? 'DB dump failed before migrate'));
                }
                $dumpFile = self::dumpFileFromResult($dump);
            }

            if ($migrate) {
                $failPhase = 'migrate';
                $mig = $kind === 'module'
                    ? AgentOps::migrateRun($id)
                    : AgentOps::migrateRun(null);
                if (empty($mig['ok'])) {
                    throw new RuntimeException((string)($mig['error'] ?? 'Migration failed for ' . $id));
                }
            } else {
                $mig = ['ok' => true, 'skipped' => true];
            }

            $failPhase = 'health';
            try {
                $health = AgentOps::health($kind === 'module' ? $id : null);
            } catch (Throwable $e) {
                $health = ['ok' => false, 'error' => $e->getMessage()];
            }
            if (empty($health['ok'])) {
                throw new RuntimeException((string)($health['error'] ?? 'Health check failed after update'));
            }

            $state = UpdateFs::readState();
            $entry = [
                'version' => (string)$remote['version'],
                'applied_at' => gmdate('c'),
                'ok' => true,
                'ref' => $remote['ref'] ?? null,
            ];
            if ($kind === 'core') {
                $state['core'] = $entry;
            } else {
                if (!isset($state['modules']) || !is_array($state['modules'])) {
                    $state['modules'] = [];
                }
                $state['modules'][$id] = $entry;
            }
            UpdateFs::writeState($state);
            UpdateCheck::refreshCheckAfterApply($id, $kind, $bundle);

            return [
                'ok' => true,
                'id' => $id,
                'kind' => $kind,
                'version' => (string)$remote['version'],
                'files_copied' => $copied,
                'migrate' => $mig,
                'health' => $health,
                'snapshot_id' => $snapshotId,
                'db_backup' => $dbBackup,
                'dump' => $dumpFile !== '' ? $dumpFile : null,
            ];
        } catch (Throwable $e) {
            return self::failApply($id, (string)($source['kind'] ?? 'module'), $e->getMessage(), $snapshotId, $dumpFile, $failPhase, $dbBackup);
        }
    }

    /**
     * Restore files (always if snapshot); restore DB only if migrate failed and dump exists.
     *
     * @return array<string,mixed>
     */
    public static function failApply(
        string $id,
        string $kind,
        string $error,
        string $snapshotId,
        string $dumpFile,
        string $failPhase,
        bool $dbBackup
    ): array {
        $rolledBack = false;
        $dbRestored = false;
        $needsManualDb = false;
        $restoreError = '';

        if ($snapshotId !== '') {
            $r = UpdateRollback::restore($snapshotId);
            $rolledBack = !empty($r['ok']);
            if (!$rolledBack) {
                $restoreError = (string)($r['error'] ?? 'file restore failed');
            }
        }

        if ($failPhase === 'migrate' && $dbBackup && $dumpFile !== '') {
            $dr = AgentOps::dbRestore($dumpFile);
            $dbRestored = !empty($dr['ok']);
            if (!$dbRestored) {
                $restoreError = trim($restoreError . ' ' . (string)($dr['error'] ?? 'db restore failed'));
            }
        } elseif ($failPhase === 'migrate' && !$dbBackup) {
            $needsManualDb = true;
        } elseif ($failPhase === 'health') {
            // Migrations may already be applied; do not auto-restore DB.
            $needsManualDb = true;
        }

        $msg = $error;
        if ($rolledBack) {
            $msg .= ' (files rolled back)';
        }
        if ($dbRestored) {
            $msg .= ' (DB restored from pre-migrate dump)';
        }
        if ($needsManualDb && !$dbRestored) {
            $msg .= ' (schema may be partially applied — review DB / enable db_backup next time)';
        }
        if ($restoreError !== '') {
            $msg .= ' [restore: ' . $restoreError . ']';
        }

        return [
            'ok' => false,
            'id' => $id,
            'kind' => $kind,
            'error' => $msg,
            'rolled_back' => $rolledBack,
            'snapshot_id' => $snapshotId !== '' ? $snapshotId : null,
            'db_backup' => $dbBackup,
            'db_restored' => $dbRestored,
            'needs_manual_db_review' => $needsManualDb,
            'fail_phase' => $failPhase,
            'dump' => $dumpFile !== '' ? $dumpFile : null,
        ];
    }

    /** @return array<string,mixed> */
    public static function jobStart(?string $target = null, bool $migrate = true, bool $dbBackup = false): array
    {
        $onlyNewer = $target === null || trim((string)$target) === '' || strtolower(trim((string)$target)) === 'all';
        $packages = UpdateCheck::resolvePackages($target, $onlyNewer);
        if ($packages === []) {
            throw new RuntimeException(t('updates.job.nothing'));
        }
        $id = bin2hex(random_bytes(8));
        $job = [
            'id' => $id,
            'status' => 'running',
            'progress' => 0,
            'message' => t('updates.job.starting'),
            'step' => 'resolve',
            'target_index' => 0,
            'targets' => $packages,
            'migrate' => $migrate,
            'db_backup' => $dbBackup || UpdateConfig::dbBackupBeforeMigrate(),
            'results' => [],
            'work' => null,
            'archives' => [],
            'error' => '',
            'created_at' => time(),
            'updated_at' => time(),
        ];
        UpdateApply::jobSave($job);
        return $job;
    }

    /** @return array<string,mixed> */
    public static function jobGet(string $id): array
    {
        $path = UpdateApply::jobPath($id);
        if (!is_file($path)) {
            throw new RuntimeException('Unknown update job');
        }
        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data)) {
            throw new RuntimeException('Corrupt update job');
        }
        return $data;
    }

    /** @return array<string,mixed> */
    public static function jobPublic(array $job): array
    {
        $targets = is_array($job['targets'] ?? null) ? $job['targets'] : [];
        $ti = (int)($job['target_index'] ?? 0);
        $cur = $targets[$ti] ?? null;
        $curId = is_array($cur) ? (string)($cur['id'] ?? '') : '';
        $found = [];
        $results = is_array($job['results'] ?? null) ? $job['results'] : [];
        foreach ($results as $row) {
            if (!is_array($row) || empty($row['update_available'])) {
                continue;
            }
            $fid = (string)($row['id'] ?? '');
            $found[] = [
                'id' => $fid,
                'label' => (string)($row['label'] ?? UpdateVersions::packageLabel($fid)),
                'local_version' => (string)($row['local_version'] ?? ''),
                'remote_version' => (string)($row['remote_version'] ?? ''),
            ];
        }
        return [
            'id' => (string)($job['id'] ?? ''),
            'kind' => (string)($job['kind'] ?? 'apply'),
            'status' => (string)($job['status'] ?? ''),
            'progress' => (int)($job['progress'] ?? 0),
            'message' => (string)($job['message'] ?? ''),
            'step' => (string)($job['step'] ?? ''),
            'target' => $curId,
            'target_label' => $curId !== '' ? UpdateVersions::packageLabel($curId) : '',
            'target_index' => $ti,
            'targets_total' => count($targets),
            'error' => (string)($job['error'] ?? ''),
            'results' => $results,
            'found' => $found,
            'found_count' => count($found),
            'last_check' => $job['last_check'] ?? null,
            'rolled_back' => !empty($job['rolled_back']),
            'needs_manual_db_review' => !empty($job['needs_manual_db_review']),
        ];
    }

    /** @return array<string,mixed> */
    public static function jobCancel(string $id): array
    {
        $job = UpdateApply::jobGet($id);
        if (($job['status'] ?? '') === 'running') {
            $job['status'] = 'cancelled';
            $job['message'] = t('updates.job.cancelled');
            $job['updated_at'] = time();
            UpdateApply::jobCleanupArchives($job);
            UpdateApply::jobSave($job);
        }
        return $job;
    }

    /** @return array<string,mixed> */
    public static function jobTick(string $id): array
    {
        $job = UpdateApply::jobGet($id);
        if (($job['status'] ?? '') !== 'running') {
            return $job;
        }
        $lock = UpdateFs::acquireLock();
        if ($lock === null) {
            $job['message'] = t('updates.job.waiting_lock');
            $job['updated_at'] = time();
            UpdateApply::jobSave($job);
            return $job;
        }
        try {
            $targets = is_array($job['targets'] ?? null) ? $job['targets'] : [];
            $ti = (int)($job['target_index'] ?? 0);
            if ($ti >= count($targets)) {
                $job['status'] = 'done';
                $job['progress'] = 100;
                $job['message'] = t('updates.job.done');
                UpdateApply::jobCleanupArchives($job);
                $job['updated_at'] = time();
                UpdateApply::jobSave($job);
                return $job;
            }
            $source = $targets[$ti];
            $sid = (string)($source['id'] ?? '');
            $step = (string)($job['step'] ?? 'resolve');
            $work = is_array($job['work'] ?? null) ? $job['work'] : [];
            $archives = is_array($job['archives'] ?? null) ? $job['archives'] : [];
            $dbBackup = !empty($job['db_backup']);

            if ($step === 'resolve') {
                $job['message'] = t('updates.job.step_resolve', ['id' => $sid]);
                $remote = UpdateGithub::resolveRemoteMeta($source);
                $cacheKey = ((string)($source['bundle'] ?? '')) . '|' . ((string)($source['repo'] ?? '')) . '|' . ((string)($remote['ref'] ?? '')) . '|' . ((string)$remote['url']);
                $work = ['remote' => $remote, 'cache_key' => $cacheKey, 'copied' => 0, 'fail_phase' => ''];
                $job['work'] = $work;
                $job['step'] = isset($archives[$cacheKey]) ? 'overlay' : 'download';
            } elseif ($step === 'download') {
                $job['message'] = t('updates.job.step_download', ['id' => $sid]);
                UpdateFs::ensureDir(UpdateFs::workDir());
                $stamp = gmdate('YmdHis') . '-' . substr(sha1((string)$work['cache_key']), 0, 8);
                $zipPath = UpdateFs::workDir() . '/' . $stamp . '.zip';
                $stageDir = UpdateFs::workDir() . '/' . $stamp . '-stage';
                UpdateGithub::downloadFile((string)$work['remote']['url'], $zipPath, (string)$source['token']);
                $work['zip'] = $zipPath;
                $work['stage'] = $stageDir;
                $job['work'] = $work;
                $job['step'] = 'verify';
            } elseif ($step === 'verify') {
                $job['message'] = t('updates.job.step_verify', ['id' => $sid]);
                $sha = (string)($work['remote']['sha256'] ?? '');
                if ($sha !== '' && !empty($work['zip'])) {
                    $got = hash_file('sha256', (string)$work['zip']);
                    if (!is_string($got) || !hash_equals(strtolower($sha), strtolower($got))) {
                        throw new RuntimeException('SHA-256 mismatch for ' . $sid);
                    }
                }
                $job['step'] = 'extract';
            } elseif ($step === 'extract') {
                $job['message'] = t('updates.job.step_extract', ['id' => $sid]);
                UpdateOverlay::extractZip((string)$work['zip'], (string)$work['stage']);
                $archives[(string)$work['cache_key']] = [
                    'zip' => (string)$work['zip'],
                    'stage' => (string)$work['stage'],
                ];
                $job['archives'] = $archives;
                $job['step'] = 'overlay';
            } elseif ($step === 'overlay') {
                $job['message'] = t('updates.job.step_overlay', ['id' => $sid]);
                $key = (string)($work['cache_key'] ?? '');
                $stage = (string)(($archives[$key]['stage'] ?? $work['stage'] ?? ''));
                $payloadRoot = UpdateOverlay::findPayloadRoot(
                    $stage,
                    (string)($source['path_prefix'] ?? ''),
                    (string)$source['kind'],
                    $sid
                );
                $paths = UpdateOverlay::collectOverlayPaths(
                    $payloadRoot,
                    (string)$source['kind'],
                    $sid,
                    (string)($source['bundle'] ?? '')
                );
                $snap = UpdateRollback::snapshotBeforeOverlay($paths, $sid);
                $work['snapshot_id'] = (string)$snap['id'];
                $work['fail_phase'] = 'overlay';
                $work['copied'] = UpdateOverlay::overlay(
                    $payloadRoot,
                    (string)$source['kind'],
                    $sid,
                    (string)($source['bundle'] ?? '')
                );
                $job['work'] = $work;
                if (!empty($job['migrate']) && $dbBackup) {
                    $job['step'] = 'migrate'; // dump happens at start of migrate step
                } else {
                    $job['step'] = !empty($job['migrate']) ? 'migrate' : 'health';
                }
            } elseif ($step === 'migrate') {
                $job['message'] = t('updates.job.step_migrate', ['id' => $sid]);
                $work['fail_phase'] = 'migrate';
                if ($dbBackup && empty($work['dump_file'])) {
                    $dump = AgentOps::dbDump('update-pre-' . $sid);
                    if (empty($dump['ok'])) {
                        throw new RuntimeException((string)($dump['error'] ?? 'DB dump failed before migrate'));
                    }
                    $work['dump_file'] = self::dumpFileFromResult($dump);
                }
                $mig = $source['kind'] === 'module'
                    ? AgentOps::migrateRun($sid)
                    : AgentOps::migrateRun(null);
                $work['migrate'] = $mig;
                $job['work'] = $work;
                if (empty($mig['ok'])) {
                    throw new RuntimeException((string)($mig['error'] ?? 'Migration failed for ' . $sid));
                }
                $job['step'] = 'health';
            } elseif ($step === 'health') {
                $job['message'] = t('updates.job.step_health', ['id' => $sid]);
                $work['fail_phase'] = 'health';
                $job['work'] = $work;
                try {
                    $health = AgentOps::health($source['kind'] === 'module' ? $sid : null);
                } catch (Throwable $e) {
                    $health = ['ok' => false, 'error' => $e->getMessage()];
                }
                $work['health'] = $health;
                $job['work'] = $work;
                if (empty($health['ok'])) {
                    throw new RuntimeException((string)($health['error'] ?? 'Health check failed after update'));
                }
                $job['step'] = 'finalize';
            } elseif ($step === 'finalize') {
                $job['message'] = t('updates.job.step_finalize', ['id' => $sid]);
                $remote = is_array($work['remote'] ?? null) ? $work['remote'] : [];
                $mig = $work['migrate'] ?? ['ok' => true, 'skipped' => true];
                $health = $work['health'] ?? ['ok' => true, 'skipped' => true];
                $state = UpdateFs::readState();
                $entry = [
                    'version' => (string)($remote['version'] ?? ''),
                    'applied_at' => gmdate('c'),
                    'ok' => true,
                    'ref' => $remote['ref'] ?? null,
                ];
                if ($source['kind'] === 'core') {
                    $state['core'] = $entry;
                } else {
                    if (!isset($state['modules']) || !is_array($state['modules'])) {
                        $state['modules'] = [];
                    }
                    $state['modules'][$sid] = $entry;
                }
                UpdateFs::writeState($state);
                UpdateCheck::refreshCheckAfterApply(
                    $sid,
                    (string)($source['kind'] ?? 'module'),
                    (string)($source['bundle'] ?? '')
                );
                $job['results'][] = [
                    'ok' => true,
                    'id' => $sid,
                    'kind' => $source['kind'],
                    'version' => (string)($remote['version'] ?? ''),
                    'files_copied' => (int)($work['copied'] ?? 0),
                    'migrate' => $mig,
                    'health' => $health,
                    'snapshot_id' => $work['snapshot_id'] ?? null,
                    'db_backup' => $dbBackup,
                ];
                $job['work'] = null;
                $job['target_index'] = $ti + 1;
                $job['step'] = 'resolve';
            } else {
                $job['step'] = 'resolve';
            }

            $nTargets = max(1, count($targets));
            $si = array_search((string)$job['step'], UpdateApply::JOB_STEPS, true);
            if ($si === false) {
                $si = 0;
            }
            $doneTargets = (int)$job['target_index'];
            $frac = ($doneTargets + (($si + 1) / max(1, count(UpdateApply::JOB_STEPS)))) / $nTargets;
            if ($doneTargets >= $nTargets) {
                $job['status'] = 'done';
                $job['progress'] = 100;
                $job['message'] = t('updates.job.done');
                UpdateApply::jobCleanupArchives($job);
            } else {
                $job['progress'] = (int)min(99, floor($frac * 100));
            }
            $job['updated_at'] = time();
            UpdateApply::jobSave($job);
            return $job;
        } catch (Throwable $e) {
            $work = is_array($job['work'] ?? null) ? $job['work'] : [];
            $fail = self::failApply(
                (string)(($job['targets'][(int)($job['target_index'] ?? 0)]['id'] ?? '')),
                (string)(($job['targets'][(int)($job['target_index'] ?? 0)]['kind'] ?? 'module')),
                $e->getMessage(),
                (string)($work['snapshot_id'] ?? ''),
                (string)($work['dump_file'] ?? ''),
                (string)($work['fail_phase'] ?? ''),
                !empty($job['db_backup'])
            );
            $job['status'] = 'error';
            $job['error'] = (string)($fail['error'] ?? $e->getMessage());
            $job['message'] = $job['error'];
            $job['rolled_back'] = !empty($fail['rolled_back']);
            $job['needs_manual_db_review'] = !empty($fail['needs_manual_db_review']);
            $job['results'][] = $fail;
            $job['updated_at'] = time();
            UpdateApply::jobCleanupArchives($job);
            UpdateApply::jobSave($job);
            return $job;
        } finally {
            UpdateFs::releaseLock($lock);
        }
    }

    /** @param array<string,mixed> $dumpResult from AgentOps::dbDump */
    private static function dumpFileFromResult(array $dumpResult): string
    {
        $d = $dumpResult['dump'] ?? null;
        if (is_string($d) && $d !== '') {
            return $d;
        }
        if (!is_array($d)) {
            return '';
        }
        foreach (['path', 'file', 'filename', 'name'] as $k) {
            $v = trim((string)($d[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    public static function jobPath(string $id): string
    {
        $id = preg_replace('/[^a-f0-9]/i', '', $id) ?? '';
        if ($id === '') {
            throw new InvalidArgumentException('Bad job id');
        }
        return UpdateFs::workDir() . '/job-' . $id . '.json';
    }

    /** @param array<string,mixed> $job */
    public static function jobSave(array $job): void
    {
        UpdateFs::ensureDir(UpdateFs::workDir());
        $path = UpdateApply::jobPath((string)$job['id']);
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException('Cannot write update job');
        }
    }

    /** @param array<string,mixed> $job */
    public static function jobCleanupArchives(array &$job): void
    {
        $archives = is_array($job['archives'] ?? null) ? $job['archives'] : [];
        foreach ($archives as $a) {
            if (!is_array($a)) {
                continue;
            }
            if (!empty($a['zip'])) {
                @unlink((string)$a['zip']);
            }
            if (!empty($a['stage'])) {
                UpdateFs::rmTree((string)$a['stage']);
            }
        }
        $job['archives'] = [];
        $work = is_array($job['work'] ?? null) ? $job['work'] : null;
        if ($work !== null) {
            if (!empty($work['zip'])) {
                @unlink((string)$work['zip']);
            }
            if (!empty($work['stage'])) {
                UpdateFs::rmTree((string)$work['stage']);
            }
            $job['work'] = null;
        }
    }
}
