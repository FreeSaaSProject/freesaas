<?php
declare(strict_types=1);

/**
 * Warm-standby sync: pull full + incr packs from FTP and apply.
 */
final class BackupStandby
{
    /**
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        return [
            'ok' => true,
            'standby_mode' => StandbyMode::mode(),
            'applied_full' => BackupConfig::standbyAppliedFull(),
            'applied_cursor' => BackupConfig::standbyAppliedCursor(),
            'applied_seq' => BackupConfig::standbyAppliedSeq(),
            'incr_mode' => BackupConfig::incrMode(),
            'probe_url' => trim((string)Config::get('app', 'standby_probe_url', '')),
            'cdc' => BackupCdc::status(),
        ];
    }

    /**
     * Fetch remote listing, apply new full (empty-DB redeploy) and/or pending incrs.
     *
     * @return array<string,mixed>
     */
    public static function sync(bool $allowFullRedeploy = true): array
    {
        // Incoming replication only while passive. Active = promoted: local writes must not
        // be overwritten by newer primary packs / full redeploy.
        if (!StandbyMode::isPassive()) {
            return [
                'ok' => false,
                'error' => StandbyMode::isActive() ? 'standby_active' : 'not_standby',
                'hint' => StandbyMode::isActive()
                    ? 'Incoming sync disabled while app.standby=active (promoted). Set passive to resume pull, or demote via controlled failback.'
                    : 'Set app.standby=passive on this host to pull packs',
                'http' => 400,
            ];
        }
        $target = BackupFtpTarget::fromConfig();
        $applied = [];
        $errors = [];
        try {
            $instance = BackupConfig::instance();
            $sets = $target->listSets($instance);
            $fulls = [];
            $incrs = [];
            foreach ($sets as $s) {
                $k = (string)($s['kind'] ?? '');
                if ($k === 'incr') {
                    $incrs[] = $s;
                } elseif ($k === 'pack' || $k === 'light') {
                    // Hard full or nightly light — both are DB baselines
                    $fulls[] = $s;
                }
            }
            // Newest first already from listSets
            $latestFull = $fulls[0] ?? null;
            $appliedFull = BackupConfig::standbyAppliedFull();

            if ($latestFull !== null) {
                $name = (string)$latestFull['name'];
                if ($name !== $appliedFull) {
                    if (!$allowFullRedeploy) {
                        return [
                            'ok' => false,
                            'error' => 'full_pending',
                            'latest_full' => $name,
                            'applied_full' => $appliedFull,
                            'hint' => 'Re-run with full redeploy allowed (empty DB / DROP+CREATE)',
                        ];
                    }
                    if (!StandbyMode::isPassive()) {
                        return [
                            'ok' => false,
                            'applied' => $applied,
                            'error' => 'standby_promoted_mid_sync',
                            'hint' => 'Promotion mid-sync; incoming apply aborted',
                        ];
                    }
                    $r = self::redeployFull((string)$latestFull['path']);
                    $applied[] = ['type' => 'full', 'path' => $latestFull['path'], 'result' => $r];
                    if (empty($r['ok'])) {
                        return ['ok' => false, 'applied' => $applied, 'error' => (string)($r['error'] ?? 'full failed')];
                    }
                    BackupConfig::setStandbyAppliedFull($name);
                    BackupConfig::setStandbyAppliedCursor(0);
                    BackupConfig::setStandbyAppliedSeq(0);
                    $appliedFull = $name;
                }
            }

            // CDC packs: differential → only newest superseding pack; chain → unbroken s001…sNNN
            $pending = self::selectPendingCdcPacks(
                $incrs,
                BackupConfig::standbyAppliedCursor(),
                BackupConfig::standbyAppliedSeq(),
                $appliedFull
            );
            if (!empty($pending['error'])) {
                $errors[] = (string)$pending['error'];
            }
            foreach ((array)($pending['packs'] ?? []) as $set) {
                if (!is_array($set)) {
                    continue;
                }
                if (!StandbyMode::isPassive()) {
                    $errors[] = 'standby_promoted_mid_sync';
                    break;
                }
                $local = self::downloadAndOpenPack((string)$set['path']);
                if (empty($local['ok'])) {
                    $errors[] = (string)($local['error'] ?? 'download failed');
                    break;
                }
                $manifest = is_array($local['manifest'] ?? null) ? $local['manifest'] : [];
                $cursorTo = (int)($manifest['cursor_to'] ?? 0);
                $cursorFrom = (int)($manifest['cursor_from'] ?? 0);
                $mode = (string)($manifest['incr_mode'] ?? ($set['cdc_mode'] ?? 'chain'));
                $seq = (int)($manifest['incr_seq'] ?? ($set['seq'] ?? 0));
                $baseFull = basename((string)($manifest['base_full'] ?? ''));
                $appliedCursor = BackupConfig::standbyAppliedCursor();
                if ($cursorTo <= $appliedCursor) {
                    self::rmTree((string)$local['dir']);
                    if ($seq > BackupConfig::standbyAppliedSeq()) {
                        BackupConfig::setStandbyAppliedSeq($seq);
                    }
                    continue;
                }
                if ($baseFull !== '' && $appliedFull !== '' && $baseFull !== $appliedFull
                    && strpos($appliedFull, $baseFull) === false
                    && strpos($baseFull, preg_replace('/\.tar(\.enc)?$/i', '', $appliedFull) ?? '') === false
                ) {
                    self::rmTree((string)$local['dir']);
                    // Differential packs from a previous light are expected after nightly redeploy —
                    // skip and try the next candidate instead of failing the whole sync.
                    if ($mode === 'differential') {
                        continue;
                    }
                    $errors[] = 'incr base_full mismatch: ' . $baseFull . ' vs applied ' . $appliedFull;
                    break;
                }
                if ($mode !== 'differential' && $cursorFrom > $appliedCursor && $appliedCursor > 0) {
                    self::rmTree((string)$local['dir']);
                    $errors[] = 'incr gap: cursor_from=' . $cursorFrom . ' applied=' . $appliedCursor;
                    break;
                }
                $sqlGz = (string)($local['dir'] . '/incr.sql.gz');
                if (!is_file($sqlGz)) {
                    $sqlGz = self::findFile((string)$local['dir'], 'incr.sql.gz');
                }
                if ($sqlGz === '' || !is_file($sqlGz)) {
                    self::rmTree((string)$local['dir']);
                    $errors[] = 'incr.sql.gz missing in ' . (string)$set['name'];
                    break;
                }
                $sqlPath = (string)$local['dir'] . '/_incr.sql';
                $raw = gzdecode((string)file_get_contents($sqlGz));
                if ($raw === false) {
                    self::rmTree((string)$local['dir']);
                    $errors[] = 'gunzip failed';
                    break;
                }
                file_put_contents($sqlPath, $raw);
                $ap = BackupCdc::applyIncrSqlFile($sqlPath);
                self::rmTree((string)$local['dir']);
                $applied[] = [
                    'type' => $mode === 'differential' ? 'diff' : 'incr',
                    'path' => $set['path'],
                    'incr_mode' => $mode,
                    'incr_seq' => $seq,
                    'cursor_from' => $cursorFrom,
                    'cursor_to' => $cursorTo,
                    'result' => $ap,
                ];
                if (empty($ap['ok'])) {
                    $errors[] = (string)($ap['error'] ?? 'apply failed');
                    break;
                }
                BackupConfig::setStandbyAppliedCursor($cursorTo);
                if ($seq > 0) {
                    BackupConfig::setStandbyAppliedSeq($seq);
                }
            }
        } finally {
            $target->close();
        }

        return [
            'ok' => $errors === [],
            'applied' => $applied,
            'errors' => $errors,
            'status' => self::status(),
        ];
    }

    /**
     * Probe primary /health; notify via bots on failure (deduped).
     *
     * @return array<string,mixed>
     */
    public static function healthProbe(): array
    {
        if (!StandbyMode::isPassive()) {
            return ['ok' => true, 'processed' => 0, 'message' => 'not_passive'];
        }
        $url = trim((string)Config::get('app', 'standby_probe_url', ''));
        if ($url === '') {
            return ['ok' => true, 'processed' => 0, 'message' => 'no_probe_url'];
        }
        $t0 = microtime(true);
        $code = 0;
        $body = '';
        $err = '';
        try {
            if (!class_exists('SafeHttp', false)) {
                throw new RuntimeException('SafeHttp missing');
            }
            // Lightweight GET — SafeHttp may only do download; use curl
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('curl_init failed');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);
            if ($cerr !== '') {
                $err = $cerr;
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $healthy = $err === '' && $code >= 200 && $code < 400;
        $key = 'standby_last_probe_ok';
        $prev = Config::get('backups', $key, '1') === '1';
        BackupConfig::set($key, $healthy ? '1' : '0');
        BackupConfig::set('standby_last_probe_at', (string)time());
        BackupConfig::set('standby_last_probe_code', (string)$code);
        if (!$healthy && $prev && BackupConfig::notifyBots()) {
            // Health alert from passive standby — intentional exception to outbound mute.
            EventBus::emit('bots.send', [
                'platform' => BackupConfig::notifyPlatform(),
                'text' => 'FreeSaaS standby: primary health FAILED ' . $url . ' HTTP ' . $code . ' ' . $err,
                'staff_ids' => BackupConfig::notifyStaffIds(),
                'chat_id' => BackupConfig::notifyChatId(),
            ]);
        }
        return [
            'ok' => $healthy,
            'processed' => 1,
            'http_code' => $code,
            'ms' => $ms,
            'error' => $err,
            'url' => $url,
            'body_sample' => mb_substr($body, 0, 200),
        ];
    }

    /**
     * Pick CDC packs to apply after baseline.
     * Differential: one newest pack (supersedes older diffs).
     * Chain: consecutive sNNN after applied_seq, fail on gaps.
     *
     * @param list<array<string,mixed>> $incrs Newest-first listing
     * @return array{packs:list<array<string,mixed>>,error:string}
     */
    public static function selectPendingCdcPacks(
        array $incrs,
        int $appliedCursor,
        int $appliedSeq,
        string $appliedFull = ''
    ): array {
        if ($incrs === []) {
            return ['packs' => [], 'error' => ''];
        }
        $baseStamp = '';
        if (preg_match('/^(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})/', $appliedFull, $m)) {
            $baseStamp = $m[1];
        }
        $diffs = [];
        $chains = [];
        foreach ($incrs as $set) {
            if (!is_array($set)) {
                continue;
            }
            $mode = (string)($set['cdc_mode'] ?? '');
            if ($mode === '') {
                $parsed = BackupFtpTarget::parsePackName((string)($set['name'] ?? ''));
                $mode = (string)($parsed['cdc_mode'] ?? 'chain');
                $set['cdc_mode'] = $mode;
                $set['seq'] = (int)($parsed['seq'] ?? ($set['seq'] ?? 0));
            }
            if ($mode === 'differential') {
                $diffs[] = $set;
            } else {
                $chains[] = $set;
            }
        }

        if ($diffs !== []) {
            // Newest first; skip if filename seq already applied, or pack stamp is older than current light.
            foreach ($diffs as $set) {
                $seq = (int)($set['seq'] ?? 0);
                if ($seq > 0 && $seq <= $appliedSeq) {
                    continue;
                }
                if ($baseStamp !== '') {
                    $name = (string)($set['name'] ?? '');
                    if (preg_match('/^(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})/', $name, $sm)
                        && strcmp($sm[1], $baseStamp) < 0
                    ) {
                        continue;
                    }
                }
                return ['packs' => [$set], 'error' => ''];
            }
            return ['packs' => [], 'error' => ''];
        }

        $chrono = array_reverse($chains);
        $out = [];
        $expectSeq = $appliedSeq > 0 ? $appliedSeq + 1 : 0;
        $sawNumbered = false;
        foreach ($chrono as $set) {
            $seq = (int)($set['seq'] ?? 0);
            if ($seq > 0) {
                $sawNumbered = true;
                if ($seq <= $appliedSeq) {
                    continue;
                }
                if ($expectSeq === 0) {
                    $expectSeq = 1;
                }
                if ($seq > $expectSeq) {
                    return [
                        'packs' => $out,
                        'error' => 'incr chain gap: missing s' . sprintf('%03d', $expectSeq)
                            . ' (have s' . sprintf('%03d', $seq) . ')',
                    ];
                }
                if ($seq === $expectSeq) {
                    $out[] = $set;
                    $expectSeq = $seq + 1;
                }
            } else {
                if ($sawNumbered) {
                    return [
                        'packs' => $out,
                        'error' => 'incr chain mixes numbered and legacy packs',
                    ];
                }
                // Legacy unnumbered: chronological; cursor skip in apply.
                $out[] = $set;
            }
        }
        unset($appliedCursor);
        return ['packs' => $out, 'error' => ''];
    }

    /** @return array<string,mixed> */
    private static function redeployFull(string $remotePath): array
    {
        // Download/decrypt/validate dump BEFORE any DROP — a manifest-only pack must not wipe the DB.
        $dir = '';
        try {
            $opened = self::downloadAndOpenPack($remotePath);
            if (empty($opened['ok'])) {
                return [
                    'ok' => false,
                    'error' => (string)($opened['error'] ?? 'download/open failed'),
                    'remote_path' => $remotePath,
                ];
            }
            $dir = (string)($opened['dir'] ?? '');
            if ($dir === '' || !is_dir($dir)) {
                return ['ok' => false, 'error' => 'open pack dir missing', 'remote_path' => $remotePath];
            }
            $dump = self::findDbDump($dir);
            if ($dump === '') {
                return [
                    'ok' => false,
                    'error' => 'pack has no db.sql.gz / db.sql (refuse DROP)',
                    'remote_path' => $remotePath,
                ];
            }
            // Prove DDL restores on a throwaway staging table BEFORE any DROP base_*.
            $preflight = BackupRestore::preflightDumpBeforeDrop($dump);
            if (empty($preflight['ok'])) {
                return [
                    'ok' => false,
                    'error' => t('backups.err.dump_preflight', [
                        'detail' => (string)($preflight['error'] ?? 'failed'),
                    ]),
                    'remote_path' => $remotePath,
                    'dump' => basename($dump),
                    'preflight' => $preflight,
                ];
            }
            // Shared-DB guard: refuse BEFORE DROP base_* (foreign check after DROP is too late).
            $foreign = BackupRestore::foreignTablesWithRows();
            if ($foreign !== []) {
                return [
                    'ok' => false,
                    'error' => 'foreign tables with data — use a dedicated standby schema (refuse DROP)',
                    'foreign_tables' => array_slice($foreign, 0, 10),
                    'remote_path' => $remotePath,
                ];
            }
            if (!StandbyMode::isPassive()) {
                return [
                    'ok' => false,
                    'error' => 'standby_promoted_mid_sync',
                    'remote_path' => $remotePath,
                ];
            }
            if (!BackupRestore::databaseIsEmpty()) {
                self::emptyOwnedTables();
            }
            if (!BackupRestore::databaseIsEmpty()) {
                return [
                    'ok' => false,
                    'error' => 'database still not empty after dropping base_* (foreign tables present — use a dedicated standby schema)',
                    'remote_path' => $remotePath,
                ];
            }
            $r = BackupRestore::deploy([
                'file' => $dir,
                'passphrase' => BackupConfig::passphrase(),
                'migrate' => true,
            ]);
            if (!empty($r['ok'])) {
                BackupRepository::cancelAllRunning();
            }
            return $r;
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'remote_path' => $remotePath];
        } finally {
            if ($dir !== '' && is_dir($dir)) {
                self::rmTree($dir);
            }
        }
    }

    /** Locate a valid db dump under an unpacked pack (including one nesting level). */
    public static function findDbDump(string $dir): string
    {
        foreach (['db.sql.gz', 'db.sql'] as $name) {
            $p = $dir . '/' . $name;
            if (is_file($p) && filesize($p) > 0 && BackupRestore::dumpLooksValid($p)) {
                return $p;
            }
        }
        $found = self::findFile($dir, 'db.sql.gz');
        if ($found !== '' && is_file($found) && filesize($found) > 0 && BackupRestore::dumpLooksValid($found)) {
            return $found;
        }
        $found = self::findFile($dir, 'db.sql');
        if ($found !== '' && is_file($found) && filesize($found) > 0 && BackupRestore::dumpLooksValid($found)) {
            return $found;
        }
        return '';
    }

    /** DROP only FreeSaaS base_* tables — never wipe unrelated schemas/tables in a shared DB. */
    public static function emptyOwnedTables(): void
    {
        $pdo = Database::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $st = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
        while ($st && ($row = $st->fetch(PDO::FETCH_NUM))) {
            $name = (string)($row[0] ?? '');
            if (!preg_match('/^base_[A-Za-z0-9_]+$/', $name)) {
                continue;
            }
            $pdo->exec('DROP TABLE IF EXISTS `' . $name . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * @return array{ok:bool,dir?:string,manifest?:array,error?:string}
     */
    private static function downloadAndOpenPack(string $remotePath): array
    {
        try {
            // Full/light packs are large; allow long FTP pull (legacy dir sets still respect budget).
            $dl = BackupRestore::downloadRemote($remotePath, 900);
            if (empty($dl['ok'])) {
                return ['ok' => false, 'error' => 'download failed'];
            }
            $pack = (string)($dl['pack_path'] ?? '');
            $dir = (string)($dl['local_dir'] ?? '');
            $pass = BackupConfig::passphrase();
            $prep = BackupRestore::prepareLocal($pack !== '' ? $pack : $dir, $pass);
            return [
                'ok' => !empty($prep['ok']),
                'dir' => (string)($prep['local_dir'] ?? $dir),
                'manifest' => is_array($prep['manifest'] ?? null) ? $prep['manifest'] : null,
                'error' => empty($prep['ok']) ? 'prepare failed' : '',
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function findFile(string $dir, string $name): string
    {
        if (!is_dir($dir)) {
            return '';
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $info) {
            /** @var SplFileInfo $info */
            if ($info->isFile() && $info->getFilename() === $name) {
                return $info->getPathname();
            }
        }
        return '';
    }

    public static function rmTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
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
