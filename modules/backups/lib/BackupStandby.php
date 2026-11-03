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
        if (!StandbyMode::isStandby()) {
            return [
                'ok' => false,
                'error' => 'not_standby',
                'hint' => 'Set app.standby=passive|active on this host',
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
                    $r = self::redeployFull((string)$latestFull['path']);
                    $applied[] = ['type' => 'full', 'path' => $latestFull['path'], 'result' => $r];
                    if (empty($r['ok'])) {
                        return ['ok' => false, 'applied' => $applied, 'error' => (string)($r['error'] ?? 'full failed')];
                    }
                    BackupConfig::setStandbyAppliedFull($name);
                    BackupConfig::setStandbyAppliedCursor(0);
                    $appliedFull = $name;
                }
            }

            // Apply incr packs newer than applied cursor (by manifest cursor_to)
            $incrsChrono = array_reverse($incrs); // oldest first
            foreach ($incrsChrono as $set) {
                $local = self::downloadAndOpenPack((string)$set['path']);
                if (empty($local['ok'])) {
                    $errors[] = (string)($local['error'] ?? 'download failed');
                    break;
                }
                $manifest = is_array($local['manifest'] ?? null) ? $local['manifest'] : [];
                $cursorTo = (int)($manifest['cursor_to'] ?? 0);
                $cursorFrom = (int)($manifest['cursor_from'] ?? 0);
                $baseFull = basename((string)($manifest['base_full'] ?? ''));
                $appliedCursor = BackupConfig::standbyAppliedCursor();
                if ($cursorTo <= $appliedCursor) {
                    self::rmTree((string)$local['dir']);
                    continue;
                }
                if ($baseFull !== '' && $appliedFull !== '' && $baseFull !== $appliedFull
                    && strpos($appliedFull, $baseFull) === false
                    && strpos($baseFull, preg_replace('/\.tar(\.enc)?$/i', '', $appliedFull) ?? '') === false
                ) {
                    // base mismatch — need newer full first
                    self::rmTree((string)$local['dir']);
                    $errors[] = 'incr base_full mismatch: ' . $baseFull . ' vs applied ' . $appliedFull;
                    break;
                }
                if ($cursorFrom > 0 && $cursorFrom < $appliedCursor) {
                    // overlap ok
                }
                if ($cursorFrom > $appliedCursor && $appliedCursor > 0) {
                    self::rmTree((string)$local['dir']);
                    $errors[] = 'incr gap: cursor_from=' . $cursorFrom . ' applied=' . $appliedCursor;
                    break;
                }
                $sqlGz = (string)($local['dir'] . '/incr.sql.gz');
                if (!is_file($sqlGz)) {
                    // maybe still inside nested extract
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
                    'type' => 'incr',
                    'path' => $set['path'],
                    'cursor_from' => $cursorFrom,
                    'cursor_to' => $cursorTo,
                    'result' => $ap,
                ];
                if (empty($ap['ok'])) {
                    $errors[] = (string)($ap['error'] ?? 'apply failed');
                    break;
                }
                BackupConfig::setStandbyAppliedCursor($cursorTo);
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

    /** @return array<string,mixed> */
    private static function redeployFull(string $remotePath): array
    {
        // Destructive: wipe owned tables then restore — only on standby with empty-or-force.
        // Use BackupRestore::deploy which requires empty DB. Caller must empty DB first OR we empty.
        if (!BackupRestore::databaseIsEmpty()) {
            self::emptyAllTables();
        }
        try {
            return BackupRestore::deploy([
                'remote_path' => $remotePath,
                'passphrase' => BackupConfig::passphrase(),
                'migrate' => true,
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private static function emptyAllTables(): void
    {
        $pdo = Database::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $st = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
        while ($st && ($row = $st->fetch(PDO::FETCH_NUM))) {
            $name = (string)($row[0] ?? '');
            if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
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
            $dl = BackupRestore::downloadRemote($remotePath, 120);
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

    private static function findFile(string $dir, string $name): string
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

    private static function rmTree(string $dir): void
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
