<?php
declare(strict_types=1);

/**
 * Restore / greenfield deploy from a single pack (.tar / .tar.enc) or legacy stamp dir.
 * Refuses when the connect database already contains any rows.
 */
final class BackupRestore
{
    public const MAX_DOWNLOAD_BYTES = 10737418240; // 10 GiB

    /**
     * True when every table has zero rows (or no tables). Any data → false.
     */
    public static function databaseIsEmpty(): bool
    {
        try {
            $pdo = Database::pdo();
            $st = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
            if ($st === false) {
                return false;
            }
            while ($row = $st->fetch(PDO::FETCH_NUM)) {
                $name = (string)($row[0] ?? '');
                if ($name === '') {
                    continue;
                }
                // Quote safely — only allow simple identifiers
                if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                    return false;
                }
                $cnt = $pdo->query('SELECT COUNT(*) FROM `' . $name . '`')->fetchColumn();
                if ((int)$cnt > 0) {
                    return false;
                }
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function assertEmptyDatabase(): void
    {
        if (!self::databaseIsEmpty()) {
            throw new RuntimeException(t('backups.err.db_not_empty'));
        }
    }

    /** @return array{empty:bool,available:bool} */
    public static function availability(): array
    {
        $empty = self::databaseIsEmpty();
        return ['empty' => $empty, 'available' => $empty];
    }

    /**
     * Download pack from https URL into var/backups/.
     *
     * @return array{ok:bool,path:string,bytes:int,error:string}
     */
    public static function fetchUrl(string $url, ?string $destName = null): array
    {
        $url = trim($url);
        $dir = BackupConfig::workDir() . '/incoming';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if ($destName === null || $destName === '') {
            $pathPart = (string)(parse_url($url, PHP_URL_PATH) ?? '');
            $base = basename($pathPart);
            if ($base === '' || $base === '/' || $base === '.') {
                $base = 'backup-' . date('Y-m-d_H-i-s') . '.tar';
            }
            $destName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $base) ?? 'backup.tar';
        }
        $dest = $dir . '/' . $destName;
        $r = SafeHttp::downloadToFile($url, $dest, self::MAX_DOWNLOAD_BYTES, 600);
        if (empty($r['ok'])) {
            return [
                'ok' => false,
                'path' => '',
                'bytes' => 0,
                'error' => (string)($r['error'] ?? 'download failed'),
            ];
        }
        return [
            'ok' => true,
            'path' => $dest,
            'bytes' => (int)($r['bytes'] ?? filesize($dest)),
            'error' => '',
        ];
    }

    /**
     * Download a remote FTP pack (or legacy dir) into a local work dir.
     *
     * @return array{ok:bool,local_dir:string,pack_path:string,kind:string,manifest:?array}
     */
    public static function downloadRemote(string $remotePath, int $budgetSec = 300): array
    {
        $target = BackupFtpTarget::fromConfig();
        try {
            $stamp = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($remotePath)) ?? 'set';
            $localDir = BackupConfig::workDir() . '/restore-' . $stamp;
            if (!is_dir($localDir)) {
                @mkdir($localDir, 0755, true);
            }

            // Single pack file
            if (preg_match('/\.tar(\.enc)?$/i', $remotePath)) {
                $localPack = $localDir . '/' . basename($remotePath);
                $expect = $target->size($remotePath);
                if (!is_file($localPack) || ($expect >= 0 && filesize($localPack) !== $expect)) {
                    $target->get($remotePath, $localPack);
                }
                return [
                    'ok' => is_file($localPack),
                    'local_dir' => $localDir,
                    'pack_path' => $localPack,
                    'kind' => 'pack',
                    'manifest' => null,
                ];
            }

            // Legacy directory set
            $files = $target->listFiles($remotePath);
            $t0 = microtime(true);
            foreach ($files as $name) {
                if ((microtime(true) - $t0) > $budgetSec) {
                    break;
                }
                $dest = $localDir . '/' . $name;
                if (is_file($dest) && filesize($dest) === $target->size($remotePath . '/' . $name)) {
                    continue;
                }
                $target->get($remotePath . '/' . $name, $dest);
            }
            $manifest = self::readManifest($localDir . '/manifest.json');
            return [
                'ok' => is_file($localDir . '/manifest.json'),
                'local_dir' => $localDir,
                'pack_path' => '',
                'kind' => 'dir',
                'manifest' => $manifest,
            ];
        } finally {
            $target->close();
        }
    }

    /**
     * Unpack a local .tar / .tar.enc (or already-extracted dir) into local_dir.
     *
     * @return array{ok:bool,local_dir:string,manifest:?array,decrypted:bool}
     */
    public static function prepareLocal(string $packOrDir, string $passphrase = ''): array
    {
        if (is_dir($packOrDir)) {
            return [
                'ok' => is_file($packOrDir . '/manifest.json') || is_file($packOrDir . '/db.sql.gz'),
                'local_dir' => $packOrDir,
                'manifest' => self::readManifest($packOrDir . '/manifest.json'),
                'decrypted' => false,
            ];
        }
        if (!is_file($packOrDir)) {
            throw new RuntimeException(t('backups.err.restore_pack_missing'));
        }

        $localDir = dirname($packOrDir) . '/_unpacked_' . preg_replace('/[^a-zA-Z0-9_-]/', '', basename($packOrDir));
        if (!is_dir($localDir)) {
            @mkdir($localDir, 0755, true);
        }

        $tarPath = $packOrDir;
        $decrypted = false;
        if (preg_match('/\.enc$/i', $packOrDir)) {
            if ($passphrase === '') {
                throw new RuntimeException(t('backups.err.passphrase'));
            }
            $tarPath = preg_replace('/\.enc$/i', '', $packOrDir);
            if (!is_string($tarPath) || $tarPath === $packOrDir) {
                $tarPath = $packOrDir . '.decrypted.tar';
            }
            if (!is_file($tarPath)) {
                BackupCrypto::decryptFile($packOrDir, $tarPath, $passphrase);
            }
            $decrypted = true;
        }

        self::extractTar($tarPath, $localDir);
        return [
            'ok' => is_file($localDir . '/manifest.json') || is_file($localDir . '/db.sql.gz'),
            'local_dir' => $localDir,
            'manifest' => self::readManifest($localDir . '/manifest.json'),
            'decrypted' => $decrypted,
        ];
    }

    /**
     * Full deploy into empty DB: prepare → db restore (tick until done) → files/cfg.
     *
     * @param array{url?:string,file?:string,remote_path?:string,passphrase?:string,migrate?:bool} $opts
     * @return array<string,mixed>
     */
    public static function deploy(array $opts): array
    {
        self::assertEmptyDatabase();
        $passphrase = (string)($opts['passphrase'] ?? '');
        $steps = [];
        $localPack = '';
        $localDir = '';

        if (!empty($opts['url'])) {
            $dl = self::fetchUrl((string)$opts['url']);
            if (empty($dl['ok'])) {
                return ['ok' => false, 'error' => $dl['error'], 'http' => 400, 'steps' => $steps];
            }
            $localPack = $dl['path'];
            $steps[] = ['step' => 'download_url', 'status' => 'ok', 'bytes' => $dl['bytes']];
        } elseif (!empty($opts['remote_path'])) {
            $dl = self::downloadRemote((string)$opts['remote_path']);
            if (empty($dl['ok'])) {
                return ['ok' => false, 'error' => 'remote download failed', 'http' => 400, 'steps' => $steps];
            }
            $localPack = (string)$dl['pack_path'];
            $localDir = (string)$dl['local_dir'];
            $steps[] = ['step' => 'download_ftp', 'status' => 'ok', 'kind' => $dl['kind']];
            if ($localPack === '' && $localDir !== '') {
                // legacy dir already extracted
                $prep = ['ok' => true, 'local_dir' => $localDir, 'manifest' => $dl['manifest'], 'decrypted' => false];
            }
        } elseif (!empty($opts['file'])) {
            $localPack = (string)$opts['file'];
            if (!is_file($localPack) && !is_dir($localPack)) {
                return ['ok' => false, 'error' => t('backups.err.restore_pack_missing'), 'http' => 400, 'steps' => $steps];
            }
            $steps[] = ['step' => 'local_file', 'status' => 'ok'];
        } else {
            return ['ok' => false, 'error' => 'url, file, or remote_path required', 'http' => 400, 'steps' => $steps];
        }

        if (!isset($prep)) {
            $src = $localPack !== '' ? $localPack : $localDir;
            $prep = self::prepareLocal($src, $passphrase);
            $steps[] = [
                'step' => 'prepare',
                'status' => !empty($prep['ok']) ? 'ok' : 'error',
                'decrypted' => !empty($prep['decrypted']),
                'local_dir' => $prep['local_dir'],
            ];
            if (empty($prep['ok'])) {
                return ['ok' => false, 'error' => 'prepare failed', 'http' => 400, 'steps' => $steps];
            }
        }
        $localDir = (string)$prep['local_dir'];

        // Re-check emptiness before destructive SQL
        self::assertEmptyDatabase();

        $jobId = self::startDbRestore($localDir);
        $job = null;
        for ($i = 0; $i < 500; $i++) {
            $job = DbJobs::jobTick($jobId);
            if (($job['status'] ?? '') !== 'running') {
                break;
            }
        }
        $steps[] = [
            'step' => 'db_restore',
            'status' => (string)($job['status'] ?? ''),
            'job_id' => $jobId,
            'error' => (string)($job['error'] ?? ''),
        ];
        if (($job['status'] ?? '') !== 'done') {
            return [
                'ok' => false,
                'error' => (string)($job['error'] ?? 'db restore failed'),
                'http' => 500,
                'steps' => $steps,
                'job' => $job,
            ];
        }

        $files = self::applyFiles($localDir);
        $steps[] = ['step' => 'files', 'status' => 'ok', 'applied' => $files['applied'] ?? []];

        $wantMigrate = !array_key_exists('migrate', $opts) || !empty($opts['migrate']);
        if ($wantMigrate) {
            try {
                $mig = AgentOps::migrateRun(null);
                $steps[] = ['step' => 'migrate', 'status' => !empty($mig['ok']) ? 'ok' : 'error', 'result' => $mig];
            } catch (Throwable $e) {
                $steps[] = ['step' => 'migrate', 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        return [
            'ok' => true,
            'http' => 200,
            'local_dir' => $localDir,
            'manifest' => $prep['manifest'] ?? null,
            'steps' => $steps,
            'hint' => 'Run php bin/smoke.php; log in with a user from the restored dump.',
        ];
    }

    /**
     * Start DB restore from local db.sql.gz (or decrypted). Returns DbJobs id.
     */
    public static function startDbRestore(string $localDir): string
    {
        $candidates = [$localDir . '/db.sql.gz', $localDir . '/db.sql'];
        $file = '';
        foreach ($candidates as $c) {
            if (is_file($c)) {
                $file = $c;
                break;
            }
        }
        if ($file === '') {
            throw new RuntimeException(t('backups.err.restore_no_db'));
        }
        $basename = basename($file);
        $dest = DbFs::dumpsDir() . '/' . $basename;
        if (@realpath($file) !== @realpath($dest)) {
            if (!@copy($file, $dest)) {
                throw new RuntimeException('Cannot stage dump for restore');
            }
        }
        $job = DbJobs::jobStart('restore', ['file' => $basename]);
        return (string)$job['id'];
    }

    /** Extract cfg.tar and file tars; merge cfg preserving local connect/url. */
    public static function applyFiles(string $localDir): array
    {
        $stage = $localDir . '/_extract';
        if (!is_dir($stage)) {
            @mkdir($stage, 0755, true);
        }
        $applied = [];
        foreach (glob($localDir . '/*.tar') ?: [] as $tar) {
            $name = basename($tar);
            if (preg_match('/^\d{4}-\d{2}-\d{2}_/', $name)) {
                continue; // skip outer pack if left around
            }
            self::extractTar($tar, $stage);
            $applied[] = $name;
        }
        if (is_dir($stage . '/cfg')) {
            self::mergeCfg($stage . '/cfg');
            $applied[] = 'cfg-merge';
        }
        $base = rtrim(base_path(), '/');
        $destMap = [
            'UserFiles' => $base . '/UserFiles',
            'uploads' => $base . '/assets/uploads',
            'themes' => $base . '/themes',
            'log' => $base . '/log',
            'video' => $base . '/var/video',
            'audio' => $base . '/audio',
            'audioParsed' => $base . '/audioParsed',
            'audiochunks' => $base . '/audiochunks',
        ];
        foreach (glob($stage . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $bn = basename($dir);
            if (isset($destMap[$bn])) {
                self::copyTree($dir, $destMap[$bn]);
                $applied[] = $bn;
            }
        }
        return ['ok' => true, 'applied' => $applied];
    }

    public static function mergeCfg(string $restoredCfgDir): void
    {
        $live = base_path('cfg');
        $preserveConnect = [];
        $preserveApp = [];
        $liveConfig = $live . '/config.ini';
        if (is_file($liveConfig)) {
            $parsed = parse_ini_file($liveConfig, true, INI_SCANNER_RAW);
            if (is_array($parsed)) {
                $preserveConnect = is_array($parsed['connect'] ?? null) ? $parsed['connect'] : [];
                $app = is_array($parsed['app'] ?? null) ? $parsed['app'] : [];
                foreach ([
                    'base_url',
                    'session_name',
                    'session_cookie_domain',
                    'session_idle_minutes',
                    'session_remember_days',
                    'agent_token',
                    'cron_token',
                    // Standby host identity — never inherit primary's empty/missing standby.
                    'standby',
                    'standby_probe_url',
                    'standby_allow_bots',
                    'standby_allow_banks',
                    'standby_allow_gcal',
                    'standby_allow_llm',
                    'standby_allow_update',
                ] as $k) {
                    if (array_key_exists($k, $app)) {
                        $preserveApp[$k] = $app[$k];
                    }
                }
            }
        }

        self::copyTree($restoredCfgDir, $live);

        if (is_file($liveConfig) && ($preserveConnect !== [] || $preserveApp !== [])) {
            $parsed = parse_ini_file($liveConfig, true, INI_SCANNER_RAW);
            if (!is_array($parsed)) {
                $parsed = [];
            }
            if ($preserveConnect !== []) {
                $parsed['connect'] = array_merge(is_array($parsed['connect'] ?? null) ? $parsed['connect'] : [], $preserveConnect);
            }
            if ($preserveApp !== []) {
                $parsed['app'] = array_merge(is_array($parsed['app'] ?? null) ? $parsed['app'] : [], $preserveApp);
            }
            self::writeIni($liveConfig, $parsed);
        }
    }

    /** Minimal ustar extractor (files only). */
    public static function extractTar(string $tarPath, string $destDir): void
    {
        $fh = fopen($tarPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot open tar');
        }
        $longName = null;
        while (!feof($fh)) {
            $header = fread($fh, 512);
            if ($header === false || strlen($header) < 512) {
                break;
            }
            if (trim($header, "\0") === '') {
                break;
            }
            $name = $longName !== null ? $longName : rtrim(substr($header, 0, 100), "\0");
            $longName = null;
            $sizeOct = trim(substr($header, 124, 12), "\0 ");
            $size = (int)octdec($sizeOct !== '' ? $sizeOct : '0');
            $type = $header[156] ?? '0';
            $payload = '';
            if ($size > 0) {
                $payload = fread($fh, $size);
                if ($payload === false) {
                    $payload = '';
                }
                $pad = (512 - ($size % 512)) % 512;
                if ($pad > 0) {
                    fread($fh, $pad);
                }
            }
            if ($type === 'L') {
                $longName = rtrim($payload, "\0");
                continue;
            }
            if ($type !== '0' && $type !== "\0") {
                continue;
            }
            $name = str_replace('\\', '/', $name);
            if ($name === '' || strpos($name, '..') !== false) {
                continue;
            }
            $out = $destDir . '/' . $name;
            $dir = dirname($out);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            file_put_contents($out, $payload);
        }
        fclose($fh);
    }

    /** @return array<string,mixed>|null */
    private static function readManifest(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    private static function copyTree(string $src, string $dest): void
    {
        if (!is_dir($src)) {
            return;
        }
        if (!is_dir($dest)) {
            @mkdir($dest, 0755, true);
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $srcLen = strlen(rtrim(str_replace('\\', '/', $src), '/'));
        foreach ($it as $info) {
            /** @var SplFileInfo $info */
            $path = str_replace('\\', '/', $info->getPathname());
            $rel = ltrim(substr($path, $srcLen), '/');
            $target = rtrim($dest, '/') . '/' . $rel;
            if ($info->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
            } else {
                $td = dirname($target);
                if (!is_dir($td)) {
                    @mkdir($td, 0755, true);
                }
                @copy($path, $target);
            }
        }
    }

    /** @param array<string,mixed> $sections */
    private static function writeIni(string $path, array $sections): void
    {
        $buf = '';
        foreach ($sections as $sec => $pairs) {
            if (!is_array($pairs)) {
                continue;
            }
            $buf .= '[' . $sec . "]\n";
            foreach ($pairs as $k => $v) {
                $buf .= $k . '=' . (string)$v . "\n";
            }
            $buf .= "\n";
        }
        file_put_contents($path, $buf);
    }
}
