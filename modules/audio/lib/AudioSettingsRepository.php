<?php
declare(strict_types=1);

/**
 * Audio storage / FTP settings via cfg/modules/audio.ini ([storage] + stem_cache_dir in [audio]).
 * Editable on /audio/settings (SUPERADMIN). AgentHttp never returns ftp_pass.
 */
final class AudioSettingsRepository
{
    public const KEY_MODE = 'mode';
    public const KEY_INBOX = 'audio_inbox';
    public const KEY_PARSED = 'audio_parsed';
    public const KEY_CHUNKS = 'audio_chunks';
    public const KEY_FTP_HOST = 'ftp_host';
    public const KEY_FTP_PORT = 'ftp_port';
    public const KEY_FTP_USER = 'ftp_user';
    public const KEY_FTP_PASS = 'ftp_pass';
    public const KEY_FTP_BASE = 'ftp_base_path';
    public const KEY_FTP_HTTP = 'ftp_http_url';
    public const KEY_STEM_CACHE = 'stem_cache_dir';

    /** Ini section for a storageConfig key. */
    private static function sectionFor(string $key): string
    {
        return $key === self::KEY_STEM_CACHE ? 'audio' : 'storage';
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = Config::get(self::sectionFor($key), $key, $default);
        if ($v === null) {
            return $default;
        }

        return (string)$v;
    }

    public static function set(string $key, string $value): void
    {
        Config::set(self::sectionFor($key), $key, $value);
    }

    /**
     * Resolved storage config for FileStorage.
     *
     * @return array{mode:string,audio_inbox:string,audio_parsed:string,audio_chunks:string,ftp_host:string,ftp_port:string,ftp_user:string,ftp_pass:string,ftp_base_path:string,ftp_http_url:string,stem_cache_dir:string}
     */
    public static function storageConfig(): array
    {
        $ini = Config::section('storage');
        $audioIni = Config::section('audio');

        $pick = static function (string $key, string $default) use ($ini): string {
            $v = $ini[$key] ?? null;
            if ($v !== null && $v !== '') {
                return (string)$v;
            }

            return $default;
        };

        return [
            'mode' => $pick(self::KEY_MODE, 'local'),
            'audio_inbox' => $pick(self::KEY_INBOX, 'audio'),
            'audio_parsed' => $pick(self::KEY_PARSED, 'audioParsed'),
            'audio_chunks' => $pick(self::KEY_CHUNKS, 'audiochunks'),
            'ftp_host' => $pick(self::KEY_FTP_HOST, ''),
            'ftp_port' => $pick(self::KEY_FTP_PORT, '21'),
            'ftp_user' => $pick(self::KEY_FTP_USER, ''),
            'ftp_pass' => (string)($ini[self::KEY_FTP_PASS] ?? ''),
            'ftp_base_path' => $pick(self::KEY_FTP_BASE, '/'),
            'ftp_http_url' => $pick(self::KEY_FTP_HTTP, ''),
            'stem_cache_dir' => (string)($audioIni[self::KEY_STEM_CACHE] ?? 'var/audio_stem_cache'),
        ];
    }

    public static function setFtpPass(string $pass): void
    {
        self::set(self::KEY_FTP_PASS, $pass);
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
