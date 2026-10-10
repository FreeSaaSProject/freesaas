<?php
declare(strict_types=1);

/**
 * Module-owned KV settings for audio storage paths / FTP.
 * Editable only on /audio/settings (SUPERADMIN). AgentHttp never returns ftp_pass.
 */
final class AudioSettingsRepository
{
    public const KEY_MODE = 'storage.mode';
    public const KEY_INBOX = 'storage.audio_inbox';
    public const KEY_PARSED = 'storage.audio_parsed';
    public const KEY_CHUNKS = 'storage.audio_chunks';
    public const KEY_FTP_HOST = 'storage.ftp_host';
    public const KEY_FTP_PORT = 'storage.ftp_port';
    public const KEY_FTP_USER = 'storage.ftp_user';
    public const KEY_FTP_PASS = 'storage.ftp_pass';
    public const KEY_FTP_BASE = 'storage.ftp_base_path';
    public const KEY_FTP_HTTP = 'storage.ftp_http_url';
    public const KEY_STEM_CACHE = 'stem_cache_dir';

    /** @return array<string,string> */
    public static function all(): array
    {
        if (!Database::tableExists('base_audio_settings')) {
            return [];
        }
        $out = [];
        $rows = Database::pdo()->query('SELECT setting_key, setting_value FROM base_audio_settings')->fetchAll();
        foreach ($rows ?: [] as $row) {
            $out[(string)$row['setting_key']] = (string)$row['setting_value'];
        }
        return $out;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (!Database::tableExists('base_audio_settings')) {
            return $default;
        }
        $st = Database::pdo()->prepare('SELECT setting_value FROM base_audio_settings WHERE setting_key = ? LIMIT 1');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null) {
            return $default;
        }
        return (string)$v;
    }

    public static function set(string $key, string $value): void
    {
        if (!Database::tableExists('base_audio_settings')) {
            throw new RuntimeException('base_audio_settings missing — run schema migrate');
        }
        $now = time();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_audio_settings (setting_key, setting_value, updated_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
        );
        $st->execute([$key, $value, $now]);
    }

    /**
     * Resolved storage config for FileStorage.
     * DB overrides win over cfg/modules/audio.ini [storage] defaults.
     *
     * @return array{mode:string,audio_inbox:string,audio_parsed:string,audio_chunks:string,ftp_host:string,ftp_port:string,ftp_user:string,ftp_pass:string,ftp_base_path:string,ftp_http_url:string,stem_cache_dir:string}
     */
    public static function storageConfig(): array
    {
        $ini = Config::section('storage');
        $audioIni = Config::section('audio');
        $db = self::all();

        $pick = static function (string $dbKey, string $iniKey, string $default) use ($db, $ini): string {
            if (isset($db[$dbKey]) && $db[$dbKey] !== '') {
                return $db[$dbKey];
            }
            $v = $ini[$iniKey] ?? null;
            if ($v !== null && $v !== '') {
                return (string)$v;
            }
            return $default;
        };

        // Empty string in DB is intentional (cleared password); do not fall back to ini.
        $ftpPass = array_key_exists(self::KEY_FTP_PASS, $db)
            ? (string)$db[self::KEY_FTP_PASS]
            : (string)($ini['ftp_pass'] ?? '');

        return [
            'mode' => $pick(self::KEY_MODE, 'mode', 'local'),
            'audio_inbox' => $pick(self::KEY_INBOX, 'audio_inbox', 'audio'),
            'audio_parsed' => $pick(self::KEY_PARSED, 'audio_parsed', 'audioParsed'),
            'audio_chunks' => $pick(self::KEY_CHUNKS, 'audio_chunks', 'audiochunks'),
            'ftp_host' => $pick(self::KEY_FTP_HOST, 'ftp_host', ''),
            'ftp_port' => $pick(self::KEY_FTP_PORT, 'ftp_port', '21'),
            'ftp_user' => $pick(self::KEY_FTP_USER, 'ftp_user', ''),
            'ftp_pass' => $ftpPass,
            'ftp_base_path' => $pick(self::KEY_FTP_BASE, 'ftp_base_path', '/'),
            'ftp_http_url' => $pick(self::KEY_FTP_HTTP, 'ftp_http_url', ''),
            'stem_cache_dir' => $pick(
                self::KEY_STEM_CACHE,
                'stem_cache_dir',
                (string)($audioIni['stem_cache_dir'] ?? 'var/audio_stem_cache')
            ),
        ];
    }

    /**
     * Persist FTP password to DB and mirror into cfg/modules audio [storage] ini
     * so FileStorage keeps working if the DB row is cleared later.
     */
    public static function setFtpPass(string $pass): void
    {
        self::set(self::KEY_FTP_PASS, $pass);
        try {
            Config::set('storage', 'ftp_pass', $pass);
        } catch (Throwable $e) {
            // DB is enough for runtime; ini mirror is best-effort (permissions / missing cfg).
        }
    }

    /** Storage config for AgentHttp (FTP password never exposed). */
    public static function storageConfigForAgent(): array
    {
        $cfg = self::storageConfig();
        $configured = trim((string)($cfg['ftp_pass'] ?? '')) !== '';
        unset($cfg['ftp_pass']);
        $cfg['ftp_pass_configured'] = $configured;

        return $cfg;
    }
}
