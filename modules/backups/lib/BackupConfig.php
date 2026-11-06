<?php
declare(strict_types=1);

/** Settings from cfg/modules/backups.ini ([backups] section). */
final class BackupConfig
{
    private const SECTION = 'backups';

    /** Recommended FTP folder name (under audio base or absolute). */
    public const RECOMMENDED_FOLDER = 'freesaas-backups';

    public static function get(string $key, string $default = ''): string
    {
        return (string)Config::get(self::SECTION, $key, $default);
    }

    public static function set(string $key, string $value): void
    {
        Config::set(self::SECTION, $key, $value);
    }

    public static function enabled(): bool
    {
        return self::get('enabled', '0') === '1';
    }

    public static function dailyAt(): string
    {
        $v = trim(self::get('daily_at', '03:30'));
        if (!preg_match('/^\d{1,2}:\d{2}$/', $v)) {
            return '03:30';
        }
        return $v;
    }

    public static function keepRemote(): int
    {
        return max(1, min(90, (int)self::get('keep_remote', '7')));
    }

    public static function keepLocal(): int
    {
        return max(0, min(30, (int)self::get('keep_local', '1')));
    }

    public static function dbScope(): string
    {
        $s = self::get('db_scope', 'owned');
        return $s === 'all' ? 'all' : 'owned';
    }

    /** @return list<string> */
    public static function sourceIds(): array
    {
        $raw = self::get('sources', 'db,cfg');
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)$p))) ?? '';
            if ($p !== '' && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public static function instance(): string
    {
        $v = trim(self::get('instance', ''));
        if ($v !== '') {
            return preg_replace('/[^a-zA-Z0-9._-]/', '_', $v) ?? 'instance';
        }
        $base = (string)Config::get('app', 'base_url', '');
        $host = parse_url($base, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return preg_replace('/[^a-zA-Z0-9._-]/', '_', $host) ?? 'instance';
        }
        return 'instance';
    }

    /** @return array{host:string,port:int,user:string,pass:string,ssl:bool,passive:bool,base_path:string} */
    public static function ftpConfig(): array
    {
        return [
            'host' => self::get('ftp_host', ''),
            'port' => max(1, (int)self::get('ftp_port', '21')),
            'user' => self::get('ftp_user', ''),
            'pass' => self::get('ftp_pass', ''),
            'ssl' => self::get('ftp_ssl', '0') === '1',
            'passive' => self::get('ftp_passive', '1') !== '0',
            'base_path' => self::get('ftp_base_path', '/backups'),
        ];
    }

    public static function encryptEnabled(): bool
    {
        return self::get('encrypt', '0') === '1';
    }

    public static function passphrase(): string
    {
        $v = self::get('passphrase', '');
        if ($v !== '') {
            return $v;
        }
        // Optional off-ini secret file (standby / bootstrap hosts).
        $file = base_path('cfg/passphrase.txt');
        if (is_file($file)) {
            $fromFile = trim((string)file_get_contents($file));
            if ($fromFile !== '') {
                return $fromFile;
            }
        }
        return '';
    }

    public static function notifyUi(): bool
    {
        return self::get('notify_ui', '1') === '1';
    }

    public static function notifyBots(): bool
    {
        return self::get('notify_bots', '0') === '1';
    }

    public static function notifyOnFail(): bool
    {
        return self::get('notify_on_fail', '1') === '1';
    }

    public static function notifyOnSuccess(): bool
    {
        return self::get('notify_on_success', '0') === '1';
    }

    public static function notifyOnStale(): bool
    {
        return self::get('notify_on_stale', '1') === '1';
    }

    public static function notifyStaleHours(): int
    {
        return max(1, min(720, (int)self::get('notify_stale_hours', '48')));
    }

    /** @return list<int> */
    public static function notifyStaffIds(): array
    {
        $raw = self::get('notify_staff_ids', '');
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $id = (int)$p;
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    public static function notifyChatId(): string
    {
        return trim(self::get('notify_chat_id', ''));
    }

    public static function notifyPlatform(): string
    {
        $p = strtolower(trim(self::get('notify_platform', 'max')));
        return in_array($p, ['max', 'telegram'], true) ? $p : 'max';
    }

    public static function lastStaleNotifiedAt(): int
    {
        return max(0, (int)self::get('last_stale_notified_at', '0'));
    }

    public static function setLastStaleNotifiedAt(int $ts): void
    {
        self::set('last_stale_notified_at', (string)max(0, $ts));
    }

    public static function cdcEnabled(): bool
    {
        return self::get('cdc_enabled', '0') === '1';
    }

    /** Extra exclude tables (comma-separated) beyond BackupDoctor::CDC_EXCLUDE. */
    public static function cdcExcludeExtra(): array
    {
        $raw = self::get('cdc_exclude', '');
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$p) ?? '';
            if ($p !== '' && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /** Hours between incr packs (0 = off). */
    public static function incrEveryHours(): int
    {
        return max(0, min(168, (int)self::get('incr_every_hours', '1')));
    }

    public static function keepIncr(): int
    {
        return max(1, min(168, (int)self::get('keep_incr', '48')));
    }

    /** Hard full (all file sources) at most every N days. */
    public static function fullEveryDays(): int
    {
        return max(1, min(30, (int)self::get('full_every_days', '7')));
    }

    /** Nightly light pack (db+cfg only). Default on. */
    public static function lightNightly(): bool
    {
        return self::get('light_nightly', '1') === '1';
    }

    public static function keepLight(): int
    {
        return max(1, min(90, (int)self::get('keep_light', '7')));
    }

    /** Optional hard full with file sources. Default off. */
    public static function hardEnabled(): bool
    {
        return self::get('hard_enabled', '0') === '1';
    }

    public static function incrCursor(): int
    {
        return max(0, (int)self::get('incr_cursor', '0'));
    }

    public static function setIncrCursor(int $id): void
    {
        self::set('incr_cursor', (string)max(0, $id));
    }

    /**
     * CDC pack mode: differential (all changes since last light/full — recommended)
     * or chain (classic incremental slices; needs unbroken seq on FTP).
     */
    public static function incrMode(): string
    {
        $m = strtolower(trim(self::get('incr_mode', 'differential')));
        return $m === 'chain' ? 'chain' : 'differential';
    }

    public static function isIncrDifferential(): bool
    {
        return self::incrMode() === 'differential';
    }

    /** Journal cursor frozen at last light/full (differential from=). */
    public static function incrBaselineCursor(): int
    {
        $v = (int)self::get('incr_baseline_cursor', '0');
        if ($v > 0) {
            return $v;
        }
        // Upgrade path: treat current incr_cursor as baseline until next light/full.
        return self::incrCursor();
    }

    public static function setIncrBaselineCursor(int $id): void
    {
        self::set('incr_baseline_cursor', (string)max(0, $id));
    }

    /** Last published CDC pack sequence for current baseline (0 = none yet). */
    public static function incrSeq(): int
    {
        return max(0, (int)self::get('incr_seq', '0'));
    }

    public static function setIncrSeq(int $seq): void
    {
        self::set('incr_seq', (string)max(0, $seq));
    }

    /** After light/full: freeze baseline, reset seq, prune-ready cursor. */
    public static function resetIncrAfterBaseline(int $maxJournalId): void
    {
        $maxJournalId = max(0, $maxJournalId);
        self::setIncrCursor($maxJournalId);
        self::setIncrBaselineCursor($maxJournalId);
        self::setIncrSeq(0);
    }

    public static function lastFullRemote(): string
    {
        return trim(self::get('last_full_remote', ''));
    }

    public static function setLastFullRemote(string $path): void
    {
        self::set('last_full_remote', trim($path));
    }

    public static function lastFullAt(): int
    {
        return max(0, (int)self::get('last_full_at', '0'));
    }

    public static function setLastFullAt(int $ts): void
    {
        self::set('last_full_at', (string)max(0, $ts));
    }

    /** Standby applied cursor / full (local state on reserve host). */
    public static function standbyAppliedFull(): string
    {
        return trim(self::get('standby_applied_full', ''));
    }

    public static function standbyAppliedSeq(): int
    {
        return max(0, (int)self::get('standby_applied_seq', '0'));
    }

    public static function setStandbyAppliedSeq(int $seq): void
    {
        self::set('standby_applied_seq', (string)max(0, $seq));
    }

    public static function setStandbyAppliedFull(string $name): void
    {
        self::set('standby_applied_full', trim($name));
    }

    public static function standbyAppliedCursor(): int
    {
        return max(0, (int)self::get('standby_applied_cursor', '0'));
    }

    public static function setStandbyAppliedCursor(int $id): void
    {
        self::set('standby_applied_cursor', (string)max(0, $id));
    }

    /** Test-copy replica: manual pull of primary light + CDC packs (button / CLI). */
    public static function replicaEnabled(): bool
    {
        return self::get('replica_enabled', '0') === '1';
    }

    /** Primary's instance folder on the replica source FTP. */
    public static function replicaInstance(): string
    {
        $v = trim(self::get('replica_instance', ''));
        return $v === '' ? '' : (preg_replace('/[^a-zA-Z0-9._-]/', '_', $v) ?? '');
    }

    /** @return array{host:string,port:int,user:string,pass:string,ssl:bool,passive:bool,base_path:string} */
    public static function replicaFtpConfig(): array
    {
        return [
            'host' => self::get('replica_ftp_host', ''),
            'port' => max(1, (int)self::get('replica_ftp_port', '21')),
            'user' => self::get('replica_ftp_user', ''),
            'pass' => self::get('replica_ftp_pass', ''),
            'ssl' => self::get('replica_ftp_ssl', '0') === '1',
            'passive' => self::get('replica_ftp_passive', '1') !== '0',
            'base_path' => self::get('replica_ftp_base_path', '/' . self::RECOMMENDED_FOLDER),
        ];
    }

    /**
     * Stable key for "same backup warehouse": host|port|base_path|instance.
     * Empty string when host or instance is missing (not comparable).
     *
     * @param array{host?:string,port?:int|string,base_path?:string} $ftp
     */
    public static function ftpWarehouseKey(array $ftp, string $instance): string
    {
        $host = strtolower(trim((string)($ftp['host'] ?? '')));
        $inst = strtolower(trim($instance, '/'));
        if ($host === '' || $inst === '') {
            return '';
        }
        $port = max(1, (int)($ftp['port'] ?? 21));
        $base = strtolower(trim(str_replace('\\', '/', (string)($ftp['base_path'] ?? '')), '/'));
        return $host . '|' . $port . '|' . $base . '|' . $inst;
    }

    /**
     * True when this host's own backup target is the same warehouse as the replica source.
     * That means "I am (or look like) the primary writing there" — refuse replica pull/enable.
     * Test copies must point replica_* at the primary's folder while keeping a different
     * local backup instance/path (or empty local FTP).
     *
     * @param array{host?:string,port?:int|string,base_path?:string}|null $backupFtp
     * @param array{host?:string,port?:int|string,base_path?:string}|null $replicaFtp
     * @return array{conflict:bool,backup_key:string,replica_key:string}
     */
    public static function replicaConflictsWithBackupWriter(
        ?array $backupFtp = null,
        ?string $backupInstance = null,
        ?array $replicaFtp = null,
        ?string $replicaInstance = null
    ): array {
        $backupFtp = $backupFtp ?? self::ftpConfig();
        $replicaFtp = $replicaFtp ?? self::replicaFtpConfig();
        $backupInstance = $backupInstance ?? self::instance();
        $replicaInstance = $replicaInstance ?? self::replicaInstance();
        $bk = self::ftpWarehouseKey($backupFtp, (string)$backupInstance);
        $rk = self::ftpWarehouseKey($replicaFtp, (string)$replicaInstance);
        return [
            'conflict' => $bk !== '' && $rk !== '' && $bk === $rk,
            'backup_key' => $bk,
            'replica_key' => $rk,
        ];
    }

    /**
     * Role gate for enabling / pulling replica (not standby, not same warehouse as own backups).
     *
     * @return array{ok:bool,code:string,error:string}
     */
    public static function replicaRoleGuard(bool $requireEnabled = true): array
    {
        if ($requireEnabled && !self::replicaEnabled()) {
            return ['ok' => false, 'code' => 'disabled', 'error' => t('backups.replica.err.disabled')];
        }
        if (class_exists('StandbyMode', false) && StandbyMode::isStandby()) {
            return ['ok' => false, 'code' => 'standby', 'error' => t('backups.replica.err.standby')];
        }
        $same = self::replicaConflictsWithBackupWriter();
        if (!empty($same['conflict'])) {
            return [
                'ok' => false,
                'code' => 'same_target',
                'error' => t('backups.replica.err.same_target'),
            ];
        }
        return ['ok' => true, 'code' => '', 'error' => ''];
    }

    public static function replicaPassphrase(): string
    {
        return self::get('replica_passphrase', '');
    }

    /** @return array{full:string,diff:string,at:int} */
    public static function replicaApplied(): array
    {
        return [
            'full' => trim(self::get('replica_applied_full', '')),
            'diff' => trim(self::get('replica_applied_diff', '')),
            'at' => max(0, (int)self::get('replica_applied_at', '0')),
        ];
    }

    public static function setReplicaApplied(string $full, string $diff): void
    {
        self::set('replica_applied_full', trim($full));
        self::set('replica_applied_diff', trim($diff));
        self::set('replica_applied_at', (string)time());
    }

    /**
     * Copy this host's own backup FTP target (+ instance) into the replica source fields.
     * Prefill only — change replica_instance to the primary's folder before enabling.
     * If replica is already enabled and the copy creates a same-warehouse conflict, disable it.
     *
     * @return array{disabled_replica:bool}
     */
    public static function copyFtpToReplica(): array
    {
        $ftp = self::ftpConfig();
        self::set('replica_ftp_host', $ftp['host']);
        self::set('replica_ftp_port', (string)$ftp['port']);
        self::set('replica_ftp_user', $ftp['user']);
        if ($ftp['pass'] !== '') {
            self::set('replica_ftp_pass', $ftp['pass']);
        }
        self::set('replica_ftp_ssl', $ftp['ssl'] ? '1' : '0');
        self::set('replica_ftp_passive', $ftp['passive'] ? '1' : '0');
        self::set('replica_ftp_base_path', $ftp['base_path']);
        self::set('replica_instance', self::instance());
        $disabled = false;
        if (self::replicaEnabled()) {
            $same = self::replicaConflictsWithBackupWriter();
            if (!empty($same['conflict'])) {
                self::set('replica_enabled', '0');
                $disabled = true;
            }
        }
        return ['disabled_replica' => $disabled];
    }

    /** @return array<string,mixed> */
    public static function forUi(): array
    {
        $ftp = self::ftpConfig();
        $passSet = $ftp['pass'] !== '';
        $phraseSet = self::passphrase() !== '';
        $replicaFtp = self::replicaFtpConfig();
        $replicaApplied = self::replicaApplied();
        return [
            'enabled' => self::enabled() ? '1' : '0',
            'daily_at' => self::dailyAt(),
            'keep_remote' => (string)self::keepRemote(),
            'keep_local' => (string)self::keepLocal(),
            'db_scope' => self::dbScope(),
            'sources' => implode(',', self::sourceIds()),
            'source_ids' => self::sourceIds(),
            'instance' => self::get('instance', ''),
            'ftp_host' => $ftp['host'],
            'ftp_port' => (string)$ftp['port'],
            'ftp_user' => $ftp['user'],
            'ftp_pass_set' => $passSet ? '1' : '0',
            'ftp_pass_masked' => self::maskSecret($ftp['pass']),
            'ftp_ssl' => $ftp['ssl'] ? '1' : '0',
            'ftp_passive' => $ftp['passive'] ? '1' : '0',
            'ftp_base_path' => $ftp['base_path'],
            'encrypt' => self::encryptEnabled() ? '1' : '0',
            'passphrase_set' => $phraseSet ? '1' : '0',
            'passphrase_masked' => self::maskSecret(self::passphrase()),
            'notify_ui' => self::notifyUi() ? '1' : '0',
            'notify_bots' => self::notifyBots() ? '1' : '0',
            'notify_on_fail' => self::notifyOnFail() ? '1' : '0',
            'notify_on_success' => self::notifyOnSuccess() ? '1' : '0',
            'notify_on_stale' => self::notifyOnStale() ? '1' : '0',
            'notify_stale_hours' => (string)self::notifyStaleHours(),
            'notify_staff_ids' => implode(',', array_map('strval', self::notifyStaffIds())),
            'notify_chat_id' => self::notifyChatId(),
            'notify_platform' => self::notifyPlatform(),
            'cdc_enabled' => self::cdcEnabled() ? '1' : '0',
            'cdc_exclude' => self::get('cdc_exclude', ''),
            'incr_mode' => self::incrMode(),
            'incr_every_hours' => (string)self::incrEveryHours(),
            'keep_incr' => (string)self::keepIncr(),
            'full_every_days' => (string)self::fullEveryDays(),
            'light_nightly' => self::lightNightly() ? '1' : '0',
            'keep_light' => (string)self::keepLight(),
            'hard_enabled' => self::hardEnabled() ? '1' : '0',
            'incr_cursor' => (string)self::incrCursor(),
            'incr_baseline_cursor' => (string)self::incrBaselineCursor(),
            'incr_seq' => (string)self::incrSeq(),
            'last_full_remote' => self::lastFullRemote(),
            'cdc_installed_at' => self::get('cdc_installed_at', '0'),
            'replica_enabled' => self::replicaEnabled() ? '1' : '0',
            'replica_instance' => self::get('replica_instance', ''),
            'replica_ftp_host' => $replicaFtp['host'],
            'replica_ftp_port' => (string)$replicaFtp['port'],
            'replica_ftp_user' => $replicaFtp['user'],
            'replica_ftp_pass_set' => $replicaFtp['pass'] !== '' ? '1' : '0',
            'replica_ftp_pass_masked' => self::maskSecret($replicaFtp['pass']),
            'replica_ftp_ssl' => $replicaFtp['ssl'] ? '1' : '0',
            'replica_ftp_passive' => $replicaFtp['passive'] ? '1' : '0',
            'replica_ftp_base_path' => $replicaFtp['base_path'],
            'replica_passphrase_set' => self::replicaPassphrase() !== '' ? '1' : '0',
            'replica_passphrase_masked' => self::maskSecret(self::replicaPassphrase()),
            'replica_applied_full' => $replicaApplied['full'],
            'replica_applied_diff' => $replicaApplied['diff'],
            'replica_applied_at' => (string)$replicaApplied['at'],
        ];
    }

    /**
     * @param array<string,mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        $action = (string)($post['action'] ?? 'save');
        if ($action === 'copy_ftp_audio') {
            self::copyFtpFromAudio();
            return;
        }
        if ($action === 'copy_ftp_replica') {
            self::copyFtpToReplica();
            return;
        }

        self::set('enabled', !empty($post['enabled']) ? '1' : '0');
        $daily = trim((string)($post['daily_at'] ?? '03:30'));
        if (!preg_match('/^\d{1,2}:\d{2}$/', $daily)) {
            $daily = '03:30';
        }
        self::set('daily_at', $daily);
        self::set('keep_remote', (string)max(1, min(90, (int)($post['keep_remote'] ?? 7))));
        self::set('keep_local', (string)max(0, min(30, (int)($post['keep_local'] ?? 1))));
        $scope = (string)($post['db_scope'] ?? 'owned');
        self::set('db_scope', $scope === 'all' ? 'all' : 'owned');

        $src = $post['sources'] ?? [];
        if (!is_array($src)) {
            $src = [];
        }
        $ids = [];
        foreach ($src as $id) {
            $id = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)$id))) ?? '';
            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            $ids = ['db', 'cfg'];
        }
        if (!in_array('db', $ids, true)) {
            array_unshift($ids, 'db');
        }
        if (!in_array('cfg', $ids, true)) {
            $ids[] = 'cfg';
        }
        self::set('sources', implode(',', $ids));

        self::set('instance', trim((string)($post['instance'] ?? '')));
        self::set('ftp_host', trim((string)($post['ftp_host'] ?? '')));
        self::set('ftp_port', (string)max(1, (int)($post['ftp_port'] ?? 21)));
        self::set('ftp_user', trim((string)($post['ftp_user'] ?? '')));
        if (array_key_exists('ftp_pass', $post)) {
            $pass = (string)$post['ftp_pass'];
            if ($pass !== '') {
                self::set('ftp_pass', $pass);
            }
        }
        if (!empty($post['ftp_pass_clear'])) {
            self::set('ftp_pass', '');
        }
        self::set('ftp_ssl', !empty($post['ftp_ssl']) ? '1' : '0');
        self::set('ftp_passive', !empty($post['ftp_passive']) ? '1' : '0');
        self::set('ftp_base_path', trim((string)($post['ftp_base_path'] ?? '/backups')));

        self::set('encrypt', !empty($post['encrypt']) ? '1' : '0');
        if (array_key_exists('passphrase', $post)) {
            $ph = (string)$post['passphrase'];
            if ($ph !== '') {
                self::set('passphrase', $ph);
            }
        }
        if (!empty($post['passphrase_clear'])) {
            self::set('passphrase', '');
        }

        self::set('notify_ui', !empty($post['notify_ui']) ? '1' : '0');
        self::set('notify_bots', !empty($post['notify_bots']) ? '1' : '0');
        self::set('notify_on_fail', !empty($post['notify_on_fail']) ? '1' : '0');
        self::set('notify_on_success', !empty($post['notify_on_success']) ? '1' : '0');
        self::set('notify_on_stale', !empty($post['notify_on_stale']) ? '1' : '0');
        self::set('notify_stale_hours', (string)max(1, min(720, (int)($post['notify_stale_hours'] ?? 48))));

        $staff = $post['notify_staff_ids'] ?? [];
        if (is_string($staff)) {
            $staff = preg_split('/\s*,\s*/', $staff) ?: [];
        }
        if (!is_array($staff)) {
            $staff = [];
        }
        $staffIds = [];
        foreach ($staff as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, $staffIds, true)) {
                $staffIds[] = $id;
            }
        }
        self::set('notify_staff_ids', implode(',', array_map('strval', $staffIds)));
        self::set('notify_chat_id', trim((string)($post['notify_chat_id'] ?? '')));
        $plat = strtolower(trim((string)($post['notify_platform'] ?? 'max')));
        self::set('notify_platform', in_array($plat, ['max', 'telegram'], true) ? $plat : 'max');

        self::set('cdc_enabled', !empty($post['cdc_enabled']) ? '1' : '0');
        self::set('cdc_exclude', trim((string)($post['cdc_exclude'] ?? '')));
        $incrMode = strtolower(trim((string)($post['incr_mode'] ?? 'differential')));
        self::set('incr_mode', $incrMode === 'chain' ? 'chain' : 'differential');
        self::set('incr_every_hours', (string)max(0, min(168, (int)($post['incr_every_hours'] ?? 1))));
        self::set('keep_incr', (string)max(1, min(168, (int)($post['keep_incr'] ?? 48))));
        self::set('full_every_days', (string)max(1, min(30, (int)($post['full_every_days'] ?? 7))));
        self::set('light_nightly', !empty($post['light_nightly']) ? '1' : '0');
        self::set('keep_light', (string)max(1, min(90, (int)($post['keep_light'] ?? 7))));
        self::set('hard_enabled', !empty($post['hard_enabled']) ? '1' : '0');

        $wantReplica = !empty($post['replica_enabled']);
        $replicaInstance = trim((string)($post['replica_instance'] ?? ''));
        $replicaFtpHost = trim((string)($post['replica_ftp_host'] ?? ''));
        $replicaFtpPort = max(1, (int)($post['replica_ftp_port'] ?? 21));
        $replicaFtpBase = trim((string)($post['replica_ftp_base_path'] ?? '/' . self::RECOMMENDED_FOLDER));
        // Own backup target already written above from the same POST.
        if ($wantReplica) {
            $same = self::replicaConflictsWithBackupWriter(
                self::ftpConfig(),
                self::instance(),
                [
                    'host' => $replicaFtpHost,
                    'port' => $replicaFtpPort,
                    'base_path' => $replicaFtpBase,
                ],
                $replicaInstance
            );
            if (!empty($same['conflict'])) {
                throw new RuntimeException(t('backups.replica.err.same_target'));
            }
            if (class_exists('StandbyMode', false) && StandbyMode::isStandby()) {
                throw new RuntimeException(t('backups.replica.err.standby'));
            }
        }

        self::set('replica_enabled', $wantReplica ? '1' : '0');
        self::set('replica_instance', $replicaInstance);
        self::set('replica_ftp_host', $replicaFtpHost);
        self::set('replica_ftp_port', (string)$replicaFtpPort);
        self::set('replica_ftp_user', trim((string)($post['replica_ftp_user'] ?? '')));
        $rPass = (string)($post['replica_ftp_pass'] ?? '');
        if ($rPass !== '') {
            self::set('replica_ftp_pass', $rPass);
        }
        if (!empty($post['replica_ftp_pass_clear'])) {
            self::set('replica_ftp_pass', '');
        }
        self::set('replica_ftp_ssl', !empty($post['replica_ftp_ssl']) ? '1' : '0');
        self::set('replica_ftp_passive', !empty($post['replica_ftp_passive']) ? '1' : '0');
        self::set('replica_ftp_base_path', $replicaFtpBase);
        $rPhrase = (string)($post['replica_passphrase'] ?? '');
        if ($rPhrase !== '') {
            self::set('replica_passphrase', $rPhrase);
        }
        if (!empty($post['replica_passphrase_clear'])) {
            self::set('replica_passphrase', '');
        }
    }

    public static function recommendedFtpBasePath(string $audioBase = ''): string
    {
        $audioBase = trim(str_replace('\\', '/', $audioBase));
        if ($audioBase === '' || $audioBase === '/') {
            return '/' . self::RECOMMENDED_FOLDER;
        }
        return rtrim($audioBase, '/') . '/' . self::RECOMMENDED_FOLDER;
    }

    /**
     * Copy FTP credentials from audio storage and set recommended freesaas-backups folder.
     * Optionally create that folder (+ instance) on the FTP server.
     *
     * @return array{base_path:string,ensured:bool,ensure_error:string}
     */
    public static function copyFtpFromAudio(bool $ensureRemote = true): array
    {
        if (!class_exists('AudioSettingsRepository', false) && is_file(base_path('modules/audio/lib/AudioSettingsRepository.php'))) {
            require_once base_path('modules/audio/lib/AudioSettingsRepository.php');
        }
        if (!class_exists('AudioSettingsRepository', false)) {
            throw new RuntimeException(t('backups.err.audio_unavailable'));
        }
        $cfg = AudioSettingsRepository::storageConfig();
        self::set('ftp_host', (string)($cfg['ftp_host'] ?? ''));
        self::set('ftp_port', (string)max(1, (int)($cfg['ftp_port'] ?? 21)));
        self::set('ftp_user', (string)($cfg['ftp_user'] ?? ''));
        $pass = (string)($cfg['ftp_pass'] ?? '');
        if ($pass !== '') {
            self::set('ftp_pass', $pass);
        }
        $base = self::recommendedFtpBasePath((string)($cfg['ftp_base_path'] ?? '/'));
        self::set('ftp_base_path', $base);
        self::set('ftp_passive', '1');

        $ensured = false;
        $ensureError = '';
        if ($ensureRemote && trim(self::get('ftp_host', '')) !== '') {
            try {
                $t = BackupFtpTarget::fromConfig();
                try {
                    $t->ensureBaseAndInstance(self::instance());
                    $ensured = true;
                } finally {
                    $t->close();
                }
            } catch (Throwable $e) {
                $ensureError = $e->getMessage();
            }
        }
        return ['base_path' => $base, 'ensured' => $ensured, 'ensure_error' => $ensureError];
    }

    public static function maskSecret(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '—';
        }
        if (mb_strlen($value) <= 8) {
            return '••••';
        }
        return mb_substr($value, 0, 2) . '••••' . mb_substr($value, -2);
    }

    /** Work directory for in-progress sets. */
    public static function workDir(): string
    {
        $dir = base_path('var/backups');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}
