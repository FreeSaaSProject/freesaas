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
    public const BUDGET_SEC = 40;

    /** Cron entry: advance running job or start scheduled / check stale. */
    public static function cronTick(): array
    {
        if (!BackupRepository::tableReady()) {
            return ['ok' => false, 'processed' => 0, 'message' => 'table missing'];
        }

        $running = BackupRepository::findRunning();
        if ($running !== null) {
            $r = self::advance((int)$running['id']);
            return [
                'ok' => empty($r['error']),
                'processed' => 1,
                'message' => (string)($r['message'] ?? $r['phase'] ?? ''),
                'run_id' => (int)$running['id'],
                'phase' => (string)($r['phase'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
            ];
        }

        BackupNotify::checkStale();

        if (!BackupConfig::enabled()) {
            return ['ok' => true, 'processed' => 0, 'message' => 'disabled'];
        }

        if (!self::isDueToday()) {
            return ['ok' => true, 'processed' => 0, 'message' => 'not due'];
        }

        $id = self::start('cron');
        $r = self::advance($id);
        return [
            'ok' => empty($r['error']),
            'processed' => 1,
            'message' => 'started',
            'run_id' => $id,
            'phase' => (string)($r['phase'] ?? ''),
            'status' => (string)($r['status'] ?? ''),
        ];
    }

    /**
     * True when local time ≥ daily_at and no run (success or fail) was started after today's daily_at.
     * Avoids restarting every 5 minutes after a failure.
     */
    public static function isDueToday(): bool
    {
        $at = BackupConfig::dailyAt();
        [$hh, $mm] = array_map('intval', explode(':', $at));
        $dueTs = mktime($hh, $mm, 0);
        if ($dueTs === false) {
            return false;
        }
        if (time() < $dueTs) {
            return false;
        }
        $last = BackupRepository::findLast();
        if ($last === null) {
            return true;
        }
        $started = (int)($last['started_at'] ?? 0);
        return $started < $dueTs;
    }

    public static function start(string $triggeredBy = 'manual'): int
    {
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
        $id = BackupRepository::start($triggeredBy);
        $stamp = date('Y-m-d_H-i-s');
        $instance = BackupConfig::instance();
        $localDir = BackupConfig::workDir() . '/run-' . $id;
        if (!is_dir($localDir)) {
            @mkdir($localDir, 0755, true);
        }
        $packName = $stamp . '.tar';
        $state = [
            'stamp' => $stamp,
            'instance' => $instance,
            'local_dir' => $localDir,
            'pack_name' => $packName,
            'remote_file' => $instance . '/' . $packName,
            'remote_dir' => $instance . '/' . $packName, // legacy key = remote path shown in UI
            'encrypt' => BackupConfig::encryptEnabled(),
            'artifacts' => [],
            'phase_detail' => [],
        ];
        BackupRepository::update($id, 'init', $state, 0, (string)$state['remote_file']);
        return $id;
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
        try {
            $budget = self::BUDGET_SEC;
            $t0 = microtime(true);
            while ((microtime(true) - $t0) < $budget) {
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
                    $done = BackupRepository::find($id);
                    if (is_array($done)) {
                        BackupNotify::onSuccess($done);
                    }
                    self::cleanupLocal($state);
                    return $done ?? ['status' => 'done', 'phase' => 'done'];
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

    /** @param array<string,mixed> $state @return array{done:bool,state:array<string,mixed>} */
    private static function phaseDb(array $state, float $budgetLeft): array
    {
        $detail = is_array($state['phase_detail'] ?? null) ? $state['phase_detail'] : [];
        $jobId = (string)($detail['job_id'] ?? '');
        if ($jobId === '') {
            $tables = BackupConfig::dbScope() === 'all'
                ? DbTools::allTables()
                : DbTools::ownedTables();
            if ($tables === []) {
                throw new RuntimeException(t('backups.err.no_tables'));
            }
            $job = DbJobs::jobStart('dump', [
                'tables' => $tables,
                'label' => 'backup-' . (string)($state['stamp'] ?? ''),
            ]);
            $detail['job_id'] = (string)$job['id'];
            $state['phase_detail'] = $detail;
            return ['done' => false, 'state' => $state];
        }
        $t0 = microtime(true);
        $job = null;
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
        if (empty($detail['files'])) {
            $base = rtrim(base_path(), '/');
            $paths = [$base . '/cfg'];
            $files = BackupTarWriter::inventory($paths, BackupSources::globalExcludes());
            $detail['files'] = $files;
            $detail['tar'] = [];
            $state['phase_detail'] = $detail;
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
        if (empty($detail['files'])) {
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
            $target->prune((string)($state['instance'] ?? BackupConfig::instance()), BackupConfig::keepRemote());
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
        return [
            'format' => 1,
            'created_at' => date('c'),
            'instance' => (string)($state['instance'] ?? ''),
            'stamp' => (string)($state['stamp'] ?? ''),
            'core_version' => (string)(ModuleManager::all()['core']['version'] ?? ''),
            'modules' => $modules,
            'encrypt' => !empty($state['encrypt']),
            'db_scope' => BackupConfig::dbScope(),
            'files' => $files,
        ];
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
