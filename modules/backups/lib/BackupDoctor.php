<?php
declare(strict_types=1);

/**
 * Host readiness checker for backups (and future CDC-lite increments).
 * Safe to run repeatedly: trigger probe creates and drops a temporary table.
 */
final class BackupDoctor
{
    /** Tables that will not get CDC triggers (when CDC ships). */
    public const CDC_EXCLUDE = [
        'base_backup_changes',
        'base_backup_runs',
        'base_fullstats',
        'base_schema_migrations',
        'base_cron_job_state',
        'base_cron_runs',
        'base_records_stem_key_cache',
        'base_records_stem_word_cache',
        'base_client_mcp_sessions',
        'base_video_train_sessions',
    ];

    private const PROBE_TABLE = 'base_backup_probe';
    private const PROBE_TRIGGER = 'bk_probe_ai';

    /**
     * @return array{
     *   ok:bool,
     *   summary:array{ok:int,warn:int,fail:int},
     *   checks:list<array{id:string,status:string,label_key:string,detail:string,hint:string,data:array<string,mixed>}>,
     *   checked_at:int
     * }
     */
    public static function run(): array
    {
        $checks = [];
        self::checkPhpExtensions($checks);
        self::checkPhpLimits($checks);
        self::checkDisk($checks);
        self::checkMysqlServer($checks);
        self::checkMysqlGrants($checks);
        self::checkMysqlTriggerProbe($checks);
        self::checkSchemaPrimaryKeys($checks);
        self::checkCdcCoverage($checks);
        self::checkChangeLog($checks);
        self::checkPipelineConfig($checks);
        self::checkFtp($checks);
        self::checkLastRuns($checks);
        self::checkCron($checks);

        $summary = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($checks as $c) {
            $st = (string)($c['status'] ?? '');
            if (isset($summary[$st])) {
                $summary[$st]++;
            }
        }

        return [
            'ok' => $summary['fail'] === 0,
            'summary' => $summary,
            'checks' => $checks,
            'checked_at' => time(),
        ];
    }

    /**
     * @param list<array{id:string,status:string,label_key:string,detail:string,hint:string,data:array<string,mixed>}> $checks
     */
    private static function add(
        array &$checks,
        string $id,
        string $status,
        string $detail,
        string $hint = '',
        array $data = []
    ): void {
        if (!in_array($status, ['ok', 'warn', 'fail'], true)) {
            $status = 'warn';
        }
        $checks[] = [
            'id' => $id,
            'status' => $status,
            'label_key' => 'backups.doctor.check.' . str_replace('.', '_', $id),
            'detail' => $detail,
            'hint' => $hint,
            'data' => $data,
        ];
    }

    /** @param list<array{id:string,status:string,label_key:string,detail:string,hint:string,data:array<string,mixed>}> $checks */
    private static function checkPhpExtensions(array &$checks): void
    {
        $need = ['pdo_mysql' => 'pdo_mysql', 'ftp' => 'ftp', 'openssl' => 'openssl', 'zlib' => 'zlib'];
        $missing = [];
        foreach ($need as $ext => $label) {
            if (!extension_loaded($ext)) {
                $missing[] = $label;
            }
        }
        if ($missing === []) {
            self::add($checks, 'php.extensions', 'ok', 'pdo_mysql, ftp, openssl, zlib');
        } else {
            self::add(
                $checks,
                'php.extensions',
                'fail',
                'Missing: ' . implode(', ', $missing),
                'Install the listed PHP extensions on this host.',
                ['missing' => $missing]
            );
        }
    }

    /** @param list<array{id:string,status:string,label_key:string,detail:string,hint:string,data:array<string,mixed>}> $checks */
    private static function checkPhpLimits(array &$checks): void
    {
        $maxExec = (int)ini_get('max_execution_time');
        $memRaw = (string)ini_get('memory_limit');
        $memBytes = self::parseBytes($memRaw);
        $data = [
            'max_execution_time' => $maxExec,
            'memory_limit' => $memRaw,
            'memory_bytes' => $memBytes,
        ];
        $issues = [];
        // 0 = unlimited
        if ($maxExec > 0 && $maxExec < 30) {
            $issues[] = 'max_execution_time=' . $maxExec . 's (want ≥30 or 0)';
        }
        if ($memBytes > 0 && $memBytes < 128 * 1048576) {
            $issues[] = 'memory_limit=' . $memRaw . ' (want ≥128M)';
        }
        if ($issues === []) {
            $detail = 'max_execution_time=' . ($maxExec === 0 ? '0 (unlimited)' : $maxExec . 's')
                . ', memory_limit=' . $memRaw;
            self::add($checks, 'php.limits', 'ok', $detail, '', $data);
        } else {
            self::add(
                $checks,
                'php.limits',
                'warn',
                implode('; ', $issues),
                'Large packs may stall on shared hosting; cron tick advances runs in ~40s budgets.',
                $data
            );
        }
    }

    /** @param list<array{id:string,status:string,label_key:string,detail:string,hint:string,data:array<string,mixed>}> $checks */
    private static function checkDisk(array &$checks): void
    {
        $work = BackupConfig::workDir();
        if (!is_dir($work)) {
            @mkdir($work, 0750, true);
        }
        $writable = is_dir($work) && is_writable($work);
        if (!$writable) {
            self::add(
                $checks,
                'disk.writable',
                'fail',
                'Not writable: ' . $work,
                'Create var/backups and grant write permission to the PHP user.',
                ['path' => $work]
            );
        } else {
            self::add($checks, 'disk.writable', 'ok', $work, '', ['path' => $work]);
        }

        $free = @disk_free_space($work);
        $dbBytes = self::estimateDbBytes();
        $data = [
            'path' => $work,
            'free_bytes' => is_int($free) || is_float($free) ? (int)$free : null,
            'db_bytes' => $dbBytes,
        ];
        if ($free === false) {
            self::add($checks, 'disk.space', 'warn', 'Could not read free space', '', $data);
            return;
        }
        $freeBytes = (int)$free;
        $need = max($dbBytes * 2, 512 * 1048576); // ~2× DB or at least 512 MiB
        $detail = BackupNotify::formatBytes($freeBytes) . ' free; DB≈'
            . BackupNotify::formatBytes($dbBytes);
        if ($freeBytes < $need) {
            self::add(
                $checks,
                'disk.space',
                'fail',
                $detail,
                'Free at least ~2× database size before a full pack.',
                $data
            );
        } elseif ($freeBytes < $need * 2) {
            self::add(
                $checks,
                'disk.space',
                'warn',
                $detail,
                'Space is tight for concurrent packs; prune local keep_local or free disk.',
                $data
            );
        } else {
            self::add($checks, 'disk.space', 'ok', $detail, '', $data);
        }
    }

    /** @param list<array{id:string,status:string,label_key:string,detail:string,hint:string,data:array<string,mixed>}> $checks */
    private static function checkMysqlServer(array &$checks): void
    {
        try {
            $pdo = Database::pdo();
            $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            $want = ['log_bin', 'log_bin_trust_function_creators', 'max_allowed_packet', 'wait_timeout', 'sql_mode'];
            $vars = array_fill_keys($want, '');
            // Bound placeholders in SHOW VARIABLES LIKE ? fail on many builds — quote literally.
            foreach ($want as $name) {
                try {
                    $r = $pdo->query('SHOW VARIABLES LIKE ' . $pdo->quote($name));
                    $row = $r ? $r->fetch(PDO::FETCH_ASSOC) : false;
                    if (is_array($row)) {
                        $vars[$name] = (string)($row['Value'] ?? $row['value'] ?? '');
                    }
                } catch (Throwable $e) {
                    // keep empty
                }
            }
            $packet = (int)($vars['max_allowed_packet'] ?? 0);
            $detail = 'MySQL ' . $ver . '; log_bin=' . ($vars['log_bin'] !== '' ? $vars['log_bin'] : '?')
                . '; max_allowed_packet=' . BackupNotify::formatBytes($packet);
            $status = 'ok';
            $hint = '';
            if ($packet > 0 && $packet < 16 * 1048576) {
                $status = 'warn';
                $hint = 'Raise max_allowed_packet (≥16M) if large row dumps fail.';
            }
            self::add($checks, 'mysql.server', $status, $detail, $hint, [
                'version' => $ver,
                'variables' => $vars,
            ]);

            $logBinOn = self::isOn($vars['log_bin'] ?? '');
            $trust = self::isOn($vars['log_bin_trust_function_creators'] ?? '');
            if (!$logBinOn) {
                self::add(
                    $checks,
                    'mysql.log_bin',
                    'ok',
                    'log_bin=OFF — CREATE TRIGGER does not need SUPER',
                    '',
                    ['log_bin' => $vars['log_bin'] ?? '', 'trust' => $vars['log_bin_trust_function_creators'] ?? '']
                );
            } elseif ($trust) {
                self::add(
                    $checks,
                    'mysql.log_bin',
                    'ok',
                    'log_bin=ON, log_bin_trust_function_creators=ON',
                    '',
                    ['log_bin' => $vars['log_bin'] ?? '', 'trust' => $vars['log_bin_trust_function_creators'] ?? '']
                );
            } else {
                self::add(
                    $checks,
                    'mysql.log_bin',
                    'warn',
                    'log_bin=ON without trust_function_creators — TRIGGER may need SUPER',
                    'Ask the host to set log_bin_trust_function_creators=1, or confirm SUPER / live trigger probe below.',
                    ['log_bin' => $vars['log_bin'] ?? '', 'trust' => $vars['log_bin_trust_function_creators'] ?? '']
                );
            }
        } catch (Throwable $e) {
            self::add($checks, 'mysql.server', 'fail', $e->getMessage(), 'Fix [connect] in cfg/config.ini.');
        }
    }

    /** @param list<array{id:string,status:string,label_key:string,detail:string,hint:string,data:array<string,mixed>}> $checks */
    private static function checkMysqlGrants(array &$checks): void
    {
        try {
            $pdo = Database::pdo();
            $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
            $joined = strtoupper(implode("\n", array_map('strval', $grants)));
            $hasAll = strpos($joined, 'ALL PRIVILEGES') !== false;
            $need = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'TRIGGER', 'LOCK TABLES'];
            $missing = [];
            foreach ($need as $priv) {
                if ($hasAll) {
                    continue;
                }
                if (!preg_match('/\b' . preg_quote($priv, '/') . '\b/', $joined)) {
                    $missing[] = $priv;
                }
            }
            $hasSuper = $hasAll || (bool)preg_match('/\bSUPER\b/', $joined);
            $data = [
                'grants' => array_values(array_map('strval', $grants)),
                'missing' => $missing,
                'super' => $hasSuper,
                'all_privileges' => $hasAll,
            ];
            if ($missing === []) {
                self::add(
                    $checks,
                    'mysql.grants',
                    'ok',
                    $hasAll ? 'ALL PRIVILEGES' : 'Has ' . implode(', ', $need),
                    '',
                    $data
                );
            } elseif (in_array('TRIGGER', $missing, true)) {
                self::add(
                    $checks,
                    'mysql.grants',
                    'fail',
                    'Missing privileges: ' . implode(', ', $missing),
                    'CDC hourly increments need TRIGGER (+ CREATE/DROP for install). Ask host to GRANT TRIGGER ON db.*',
                    $data
                );
            } else {
                self::add(
                    $checks,
                    'mysql.grants',
                    'warn',
                    'Missing: ' . implode(', ', $missing),
                    'Full dumps usually need LOCK TABLES; restore needs CREATE/DROP.',
                    $data
                );
            }
        } catch (Throwable $e) {
            self::add($checks, 'mysql.grants', 'warn', 'SHOW GRANTS failed: ' . $e->getMessage());
        }
    }

    /** @param list<array{id:string,status:string,label_key:string,detail:string,hint:string,data:array<string,mixed>}> $checks */
    private static function checkMysqlTriggerProbe(array &$checks): void
    {
        $table = self::PROBE_TABLE;
        $trigger = self::PROBE_TRIGGER;
        try {
            $pdo = Database::pdo();
            self::cleanupProbe($pdo, $table, $trigger);
            $pdo->exec(
                'CREATE TABLE `' . $table . '` (
                    `ID` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `V` INT NOT NULL DEFAULT 0
                ) ENGINE=InnoDB'
            );
            $pdo->exec(
                'CREATE TRIGGER `' . $trigger . '` AFTER INSERT ON `' . $table . '`
                 FOR EACH ROW BEGIN
                   SET @freesaas_backup_probe = NEW.ID;
                 END'
            );
            $pdo->exec('INSERT INTO `' . $table . '` (`V`) VALUES (1)');
            self::cleanupProbe($pdo, $table, $trigger);
            self::add(
                $checks,
                'mysql.trigger_probe',
                'ok',
                'CREATE TRIGGER + INSERT succeeded (probe cleaned up)',
                '',
                ['table' => $table, 'trigger' => $trigger]
            );
        } catch (Throwable $e) {
            try {
                self::cleanupProbe(Database::pdo(), $table, $trigger);
            } catch (Throwable $ignore) {
                // best-effort
            }
            $msg = $e->getMessage();
            $hint = 'Hourly CDC increments need CREATE TRIGGER. Ask host for TRIGGER privilege '
                . 'or log_bin_trust_function_creators=1. Full daily packs still work without triggers.';
            self::add(
                $checks,
                'mysql.trigger_probe',
                'fail',
                $msg,
                $hint,
                ['table' => $table, 'trigger' => $trigger]
            );
        }
    }

    private static function cleanupProbe(PDO $pdo, string $table, string $trigger): void
    {
        try {
            $pdo->exec('DROP TRIGGER IF EXISTS `' . $trigger . '`');
        } catch (Throwable $e) {
            // ignore
        }
        try {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        } catch (Throwable $e) {
            // ignore
        }
    }

    /** @param list<array{id:string,status:string,label_key:string,detail:string,hint:string,data:array<string,mixed>}> $checks */
    private static function checkSchemaPrimaryKeys(array &$checks): void
    {
        try {
            $pdo = Database::pdo();
            $tables = $pdo->query(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'base\\_%'
                 ORDER BY TABLE_NAME"
            )->fetchAll(PDO::FETCH_COLUMN);
            $pk = $pdo->query(
                "SELECT TABLE_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'PRIMARY KEY'"
            )->fetchAll(PDO::FETCH_COLUMN);
            $pkSet = array_fill_keys(array_map('strval', $pk), true);
            $missing = [];
            foreach ($tables as $t) {
                $t = (string)$t;
                if ($t === self::PROBE_TABLE) {
                    continue;
                }
                if (!isset($pkSet[$t])) {
                    $missing[] = $t;
                }
            }
            $data = ['tables' => count($tables), 'missing' => $missing];
            if ($missing === []) {
                self::add(
                    $checks,
                    'schema.primary_keys',
                    'ok',
                    count($tables) . ' base_* tables all have PRIMARY KEY',
                    '',
                    $data
                );
            } else {
                self::add(
                    $checks,
                    'schema.primary_keys',
                    'fail',
                    'No PRIMARY KEY: ' . implode(', ', $missing),
                    'Incremental restore needs a PK on every table.',
                    $data
                );
            }
        } catch (Throwable $e) {
            self::add($checks, 'schema.primary_keys', 'fail', $e->getMessage());
        }
    }

    /** @param list<array{id:string,status:string,label_key:string,detail:string,hint:string,data:array<string,mixed>}> $checks */
    private static function checkCdcCoverage(array &$checks): void
    {
        try {
            $pdo = Database::pdo();
            $tables = $pdo->query(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'base\\_%'
                 ORDER BY TABLE_NAME"
            )->fetchAll(PDO::FETCH_COLUMN);
            $exclude = array_fill_keys(self::CDC_EXCLUDE, true);
            $exclude[self::PROBE_TABLE] = true;
            $expected = [];
            foreach ($tables as $t) {
                $t = (string)$t;
                if (isset($exclude[$t])) {
                    continue;
                }
                $expected[] = $t;
            }
            $triggers = $pdo->query(
                "SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE
                 FROM information_schema.TRIGGERS
                 WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'bk\\_%'"
            )->fetchAll(PDO::FETCH_ASSOC);
            $covered = [];
            foreach ($triggers as $row) {
                $tbl = (string)($row['EVENT_OBJECT_TABLE'] ?? '');
                if ($tbl !== '') {
                    $covered[$tbl] = true;
                }
            }
            $missing = [];
            foreach ($expected as $t) {
                if (!isset($covered[$t])) {
                    $missing[] = $t;
                }
            }
            $data = [
                'expected' => count($expected),
                'covered' => count($covered),
                'trigger_count' => count($triggers),
                'missing_sample' => array_slice($missing, 0, 20),
                'missing_total' => count($missing),
            ];
            if (count($triggers) === 0) {
                self::add(
                    $checks,
                    'cdc.triggers',
                    'warn',
                    'No bk_* CDC triggers installed (' . count($expected) . ' tables would be covered)',
                    'Hourly increments need CDC install (next step). Full packs work without triggers.',
                    $data
                );
            } elseif ($missing === []) {
                self::add(
                    $checks,
                    'cdc.triggers',
                    'ok',
                    count($covered) . ' tables covered by ' . count($triggers) . ' triggers',
                    '',
                    $data
                );
            } else {
                self::add(
                    $checks,
                    'cdc.triggers',
                    'warn',
                    count($missing) . ' tables without triggers (e.g. '
                        . implode(', ', array_slice($missing, 0, 5)) . ')',
                    'Refresh CDC triggers after migrations: php bin/freesaas.php backups cdc install',
                    $data
                );
            }
        } catch (Throwable $e) {
            self::add($checks, 'cdc.triggers', 'warn', $e->getMessage());
        }
    }

    /** @param list<array{id:string,status:string,label_key:string,detail:string,hint:string,data:array<string,mixed>}> $checks */
    private static function checkChangeLog(array &$checks): void
    {
        if (!Database::tableExists('base_backup_changes')) {
            self::add(
                $checks,
                'cdc.journal',
                'warn',
                'Table base_backup_changes missing',
                'Created when CDC is installed. Not required for full packs.',
                ['exists' => false]
            );
            return;
        }
        try {
            $pdo = Database::pdo();
            $row = $pdo->query(
                'SELECT COUNT(*) AS cnt, MIN(ID) AS min_id, MAX(ID) AS max_id, MAX(TS) AS last_ts
                 FROM base_backup_changes'
            )->fetch(PDO::FETCH_ASSOC);
            $cnt = (int)($row['cnt'] ?? 0);
            $lastTs = (string)($row['last_ts'] ?? '');
            $detail = $cnt . ' rows; last TS=' . ($lastTs !== '' ? $lastTs : '—');
            self::add($checks, 'cdc.journal', 'ok', $detail, '', [
                'exists' => true,
                'rows' => $cnt,
                'min_id' => (int)($row['min_id'] ?? 0),
                'max_id' => (int)($row['max_id'] ?? 0),
                'last_ts' => $lastTs,
            ]);
        } catch (Throwable $e) {
            self::add($checks, 'cdc.journal', 'warn', $e->getMessage());
        }
    }

    /** @param list<array{id:string,status:string,label_key:string,detail:string,hint:string,data:array<string,mixed>}> $checks */
    private static function checkPipelineConfig(array &$checks): void
    {
        $baseUrl = trim((string)Config::get('app', 'base_url', ''));
        if ($baseUrl === '') {
            self::add(
                $checks,
                'app.base_url',
                'warn',
                'app.base_url empty',
                'Set Settings → Public URL (app.base_url), e.g. https://your-host'
            );
        } else {
            self::add($checks, 'app.base_url', 'ok', $baseUrl);
        }

        if (!BackupRepository::tableReady()) {
            self::add(
                $checks,
                'pipeline.runs_table',
                'fail',
                'base_backup_runs missing',
                'php bin/freesaas.php migrate run --module=backups'
            );
        } else {
            self::add($checks, 'pipeline.runs_table', 'ok', 'base_backup_runs present');
        }

        $enabled = BackupConfig::enabled();
        $ftp = BackupConfig::ftpConfig();
        $ftpConfigured = $ftp['host'] !== '' && $ftp['user'] !== '';
        if ($enabled && !$ftpConfigured) {
            self::add(
                $checks,
                'pipeline.enabled',
                'fail',
                'Scheduled backups enabled but FTP host/user empty',
                'Configure FTP or disable the schedule.',
                ['enabled' => true, 'ftp_configured' => false]
            );
        } elseif ($enabled) {
            self::add(
                $checks,
                'pipeline.enabled',
                'ok',
                'Schedule on, daily_at=' . BackupConfig::dailyAt(),
                '',
                ['enabled' => true, 'daily_at' => BackupConfig::dailyAt()]
            );
        } else {
            self::add(
                $checks,
                'pipeline.enabled',
                'warn',
                'Schedule disabled',
                'Enable when FTP and passphrase are ready.',
                ['enabled' => false]
            );
        }

        $enc = BackupConfig::encryptEnabled();
        $pass = BackupConfig::passphrase();
        if ($enc && $pass === '') {
            self::add(
                $checks,
                'pipeline.encrypt',
                'fail',
                'Encryption on but passphrase empty',
                'Generate or set a passphrase; store a copy off-server.'
            );
        } elseif ($enc) {
            self::add($checks, 'pipeline.encrypt', 'ok', 'Encryption on, passphrase set');
        } elseif ($pass !== '') {
            self::add(
                $checks,
                'pipeline.encrypt',
                'warn',
                'Passphrase set but encrypt=0',
                'Enable encryption for off-site packs.'
            );
        } else {
            $status = ($ftp['ssl'] ?? false) ? 'warn' : 'warn';
            self::add(
                $checks,
                'pipeline.encrypt',
                $status,
                'Encryption off',
                'Recommended for FTP packs stored off-site.'
            );
        }
    }

    /** @param list<array{id:string,status:string,label_key:string,detail:string,hint:string,data:array<string,mixed>}> $checks */
    private static function checkFtp(array &$checks): void
    {
        $ftp = BackupConfig::ftpConfig();
        if ($ftp['host'] === '' || $ftp['user'] === '') {
            self::add(
                $checks,
                'pipeline.ftp',
                'warn',
                'FTP not configured',
                'Copy credentials from audio or fill host/user/pass.'
            );
            return;
        }
        try {
            $t = BackupFtpTarget::fromConfig();
            try {
                $r = $t->test();
            } finally {
                $t->close();
            }
            $path = (string)($r['base_path'] ?? '') . '/' . (string)($r['instance'] ?? '');
            self::add(
                $checks,
                'pipeline.ftp',
                'ok',
                'Write probe ok → ' . $path,
                '',
                $r
            );
        } catch (Throwable $e) {
            self::add(
                $checks,
                'pipeline.ftp',
                'fail',
                $e->getMessage(),
                'Fix host/port/user/pass/ssl/passive; ensure base path is writable.'
            );
        }
    }

    /** @param list<array{id:string,status:string,label_key:string,detail:string,hint:string,data:array<string,mixed>}> $checks */
    private static function checkLastRuns(array &$checks): void
    {
        if (!BackupRepository::tableReady()) {
            return;
        }
        $last = BackupRepository::findLastSuccess();
        if ($last === null) {
            self::add(
                $checks,
                'pipeline.last_success',
                'warn',
                'No successful run yet',
                'Click «Run now» after FTP and passphrase are ready.'
            );
            return;
        }
        $finished = (int)($last['finished_at'] ?? 0);
        $ageH = $finished > 0 ? (int)floor((time() - $finished) / 3600) : -1;
        $staleH = BackupConfig::notifyStaleHours();
        $detail = '#' . (int)($last['id'] ?? 0) . ' · age '
            . ($ageH >= 0 ? $ageH . 'h' : '?')
            . ' · ' . BackupNotify::formatBytes((int)($last['bytes'] ?? 0));
        $data = [
            'id' => (int)($last['id'] ?? 0),
            'finished_at' => $finished,
            'age_hours' => $ageH,
            'bytes' => (int)($last['bytes'] ?? 0),
            'remote' => (string)($last['remote_dir'] ?? ''),
        ];
        if ($ageH >= 0 && $ageH > $staleH) {
            self::add(
                $checks,
                'pipeline.last_success',
                'warn',
                $detail,
                'Older than notify_stale_hours=' . $staleH . '.',
                $data
            );
        } else {
            self::add($checks, 'pipeline.last_success', 'ok', $detail, '', $data);
        }

        $running = BackupRepository::findRunning();
        if ($running !== null) {
            self::add(
                $checks,
                'pipeline.running',
                'ok',
                'Run #' . (int)($running['id'] ?? 0) . ' phase=' . (string)($running['phase'] ?? ''),
                '',
                ['id' => (int)($running['id'] ?? 0), 'phase' => (string)($running['phase'] ?? '')]
            );
        }
    }

    /** @param list<array{id:string,status:string,label_key:string,detail:string,hint:string,data:array<string,mixed>}> $checks */
    private static function checkCron(array &$checks): void
    {
        $jobId = 'backups.tick';
        $rows = Cron::catalogWithState();
        $row = null;
        foreach ($rows as $r) {
            if ((string)($r['job_id'] ?? '') === $jobId) {
                $row = $r;
                break;
            }
        }
        if ($row === null) {
            self::add(
                $checks,
                'cron.tick',
                'fail',
                'Job backups.tick not registered',
                'Ensure module backups is enabled and bootstrapped.'
            );
            return;
        }
        $finished = (int)($row['last_finished_at'] ?? 0);
        $status = (string)($row['last_status'] ?? '');
        $ageMin = $finished > 0 ? (int)floor((time() - $finished) / 60) : -1;
        $data = [
            'last_finished_at' => $finished,
            'last_status' => $status,
            'last_message' => (string)($row['last_message'] ?? ''),
            'age_minutes' => $ageMin,
            'enabled' => (int)($row['enabled'] ?? 1),
        ];
        if ($finished < 1) {
            self::add(
                $checks,
                'cron.tick',
                'warn',
                'backups.tick never ran',
                'Point host crontab at /cron/tick every 5 minutes (or CLI cron tick).',
                $data
            );
            return;
        }
        $detail = 'last ' . $ageMin . ' min ago · status=' . ($status !== '' ? $status : '—');
        if ($ageMin > 15) {
            self::add(
                $checks,
                'cron.tick',
                'warn',
                $detail,
                'Cron looks stale (>15 min). Check host crontab /cron/tick.',
                $data
            );
        } else {
            self::add($checks, 'cron.tick', 'ok', $detail, '', $data);
        }
    }

    private static function estimateDbBytes(): int
    {
        try {
            $pdo = Database::pdo();
            $v = $pdo->query(
                "SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0)
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()"
            )->fetchColumn();
            return max(0, (int)$v);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function parseBytes(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-1') {
            return -1; // unlimited
        }
        if (!preg_match('/^(\d+)\s*([KMG])?B?$/i', $raw, $m)) {
            return (int)$raw;
        }
        $n = (int)$m[1];
        $u = strtoupper($m[2] ?? '');
        if ($u === 'K') {
            return $n * 1024;
        }
        if ($u === 'M') {
            return $n * 1048576;
        }
        if ($u === 'G') {
            return $n * 1073741824;
        }
        return $n;
    }

    private static function isOn(string $v): bool
    {
        $v = strtoupper(trim($v));
        return $v === 'ON' || $v === '1' || $v === 'YES' || $v === 'TRUE';
    }
}
