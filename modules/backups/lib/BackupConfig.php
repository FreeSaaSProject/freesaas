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
        $raw = self::get('sources', 'db,cfg,userfiles,uploads,themes');
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
        return self::get('passphrase', '');
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

    /** @return array<string,mixed> */
    public static function forUi(): array
    {
        $ftp = self::ftpConfig();
        $passSet = $ftp['pass'] !== '';
        $phraseSet = self::passphrase() !== '';
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
