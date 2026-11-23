<?php
declare(strict_types=1);

final class MediaConfig
{
    public const SECTION = 'media';
    public const RECOMMENDED_FTP_FOLDER = 'freesaas-media';

    /** @return list<string> */
    public static function ownerTables(): array
    {
        return ['base_procedures'];
    }

    /** @return list<string> */
    public static function scopeTables(): array
    {
        return ['base_procedure_types', 'base_products'];
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return ['before', 'after', 'zone', 'other'];
    }

    public static function get(string $key, string $default = ''): string
    {
        $v = Config::get(self::SECTION, $key, $default);
        return $v === null ? $default : (string)$v;
    }

    public static function set(string $key, string $value): void
    {
        Config::set(self::SECTION, $key, $value);
    }

    public static function mode(): string
    {
        return self::get('mode', 'local') === 'ftp' ? 'ftp' : 'local';
    }

    public static function storageRoot(): string
    {
        $root = trim(self::get('storage_root', 'var/media'));
        if ($root === '') {
            $root = 'var/media';
        }
        if ($root[0] === '/' || (strlen($root) > 2 && $root[1] === ':')) {
            return rtrim(str_replace('\\', '/', $root), '/');
        }
        return rtrim(base_path($root), '/');
    }

    public static function maxUploadBytes(): int
    {
        return max(1024, (int)self::get('max_upload_bytes', '20971520'));
    }

    public static function maxEdgePx(): int
    {
        return max(256, (int)self::get('max_edge_px', '2048'));
    }

    public static function thumbEdgePx(): int
    {
        return max(64, (int)self::get('thumb_edge_px', '400'));
    }

    public static function jpegQuality(): int
    {
        $q = (int)self::get('jpeg_quality', '85');
        return max(40, min(95, $q));
    }

    public static function stripExif(): bool
    {
        return self::get('strip_exif', '1') === '1';
    }

    /** @return list<string> */
    public static function allowedMimes(): array
    {
        $raw = self::get('allowed_mimes', 'image/jpeg,image/png,image/webp');
        $out = [];
        foreach (explode(',', $raw) as $m) {
            $m = strtolower(trim($m));
            if ($m !== '') {
                $out[] = $m;
            }
        }
        return $out !== [] ? $out : ['image/jpeg', 'image/png', 'image/webp'];
    }

    /**
     * @return array<string,string>
     */
    public static function ftpConfig(): array
    {
        return [
            'host' => self::get('ftp_host', ''),
            'port' => self::get('ftp_port', '21'),
            'user' => self::get('ftp_user', ''),
            'pass' => self::get('ftp_pass', ''),
            'ssl' => self::get('ftp_ssl', '0'),
            'passive' => self::get('ftp_passive', '1'),
            'base_path' => self::get('ftp_base_path', '/' . self::RECOMMENDED_FTP_FOLDER),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function forUi(): array
    {
        $ftp = self::ftpConfig();
        $pass = (string)$ftp['pass'];
        return [
            'mode' => self::mode(),
            'storage_root' => self::get('storage_root', 'var/media'),
            'max_upload_bytes' => self::maxUploadBytes(),
            'max_edge_px' => self::maxEdgePx(),
            'thumb_edge_px' => self::thumbEdgePx(),
            'jpeg_quality' => self::jpegQuality(),
            'strip_exif' => self::stripExif() ? 1 : 0,
            'allowed_mimes' => implode(',', self::allowedMimes()),
            'ftp_host' => $ftp['host'],
            'ftp_port' => $ftp['port'],
            'ftp_user' => $ftp['user'],
            'ftp_pass_masked' => self::maskSecret($pass),
            'ftp_pass_configured' => $pass !== '',
            'ftp_ssl' => $ftp['ssl'] === '1' ? 1 : 0,
            'ftp_passive' => $ftp['passive'] !== '0' ? 1 : 0,
            'ftp_base_path' => $ftp['base_path'],
        ];
    }

    public static function saveFromPost(array $post): void
    {
        $mode = trim((string)($post['mode'] ?? 'local')) === 'ftp' ? 'ftp' : 'local';
        self::set('mode', $mode);
        self::set('storage_root', trim((string)($post['storage_root'] ?? 'var/media')));
        self::set('max_upload_bytes', (string)max(1024, (int)($post['max_upload_bytes'] ?? 20971520)));
        self::set('max_edge_px', (string)max(256, (int)($post['max_edge_px'] ?? 2048)));
        self::set('thumb_edge_px', (string)max(64, (int)($post['thumb_edge_px'] ?? 400)));
        self::set('jpeg_quality', (string)max(40, min(95, (int)($post['jpeg_quality'] ?? 85))));
        self::set('strip_exif', !empty($post['strip_exif']) ? '1' : '0');
        self::set('allowed_mimes', trim((string)($post['allowed_mimes'] ?? 'image/jpeg,image/png,image/webp')));
        self::set('ftp_host', trim((string)($post['ftp_host'] ?? '')));
        self::set('ftp_port', (string)max(1, (int)($post['ftp_port'] ?? 21)));
        self::set('ftp_user', trim((string)($post['ftp_user'] ?? '')));
        $pass = (string)($post['ftp_pass'] ?? '');
        if ($pass !== '') {
            self::set('ftp_pass', $pass);
        }
        if (!empty($post['ftp_pass_clear'])) {
            self::set('ftp_pass', '');
        }
        self::set('ftp_ssl', !empty($post['ftp_ssl']) ? '1' : '0');
        self::set('ftp_passive', !empty($post['ftp_passive']) ? '1' : '0');
        $base = trim((string)($post['ftp_base_path'] ?? '/' . self::RECOMMENDED_FTP_FOLDER));
        if ($base === '') {
            $base = '/' . self::RECOMMENDED_FTP_FOLDER;
        }
        if ($base[0] !== '/') {
            $base = '/' . $base;
        }
        self::set('ftp_base_path', $base);
    }

    /** @return array{base_path:string} */
    public static function copyFtpFromAudio(): array
    {
        if (!class_exists('AudioSettingsRepository', false)
            && is_file(base_path('modules/audio/lib/AudioSettingsRepository.php'))
        ) {
            require_once base_path('modules/audio/lib/AudioSettingsRepository.php');
        }
        if (!class_exists('AudioSettingsRepository', false)) {
            throw new RuntimeException(t('media.err.audio_unavailable'));
        }
        $cfg = AudioSettingsRepository::storageConfig();
        return self::applyCopiedFtp(
            (string)($cfg['ftp_host'] ?? ''),
            (string)($cfg['ftp_port'] ?? '21'),
            (string)($cfg['ftp_user'] ?? ''),
            (string)($cfg['ftp_pass'] ?? ''),
            (string)($cfg['ftp_base_path'] ?? '/')
        );
    }

    /** @return array{base_path:string} */
    public static function copyFtpFromBackups(): array
    {
        if (!class_exists('BackupConfig', false)
            && is_file(base_path('modules/backups/lib/BackupConfig.php'))
        ) {
            require_once base_path('modules/backups/lib/BackupConfig.php');
        }
        if (!class_exists('BackupConfig', false)) {
            throw new RuntimeException(t('media.err.backups_unavailable'));
        }
        $ftp = BackupConfig::ftpConfig();
        return self::applyCopiedFtp(
            (string)($ftp['host'] ?? ''),
            (string)($ftp['port'] ?? '21'),
            (string)($ftp['user'] ?? ''),
            (string)($ftp['pass'] ?? ''),
            (string)($ftp['base_path'] ?? '/')
        );
    }

    /**
     * @return array{base_path:string}
     */
    private static function applyCopiedFtp(
        string $host,
        string $port,
        string $user,
        string $pass,
        string $sourceBase
    ): array {
        self::set('mode', 'ftp');
        self::set('ftp_host', $host);
        self::set('ftp_port', (string)max(1, (int)$port));
        self::set('ftp_user', $user);
        if ($pass !== '') {
            self::set('ftp_pass', $pass);
        }
        self::set('ftp_passive', '1');
        $base = '/' . self::RECOMMENDED_FTP_FOLDER;
        self::set('ftp_base_path', $base);
        unset($sourceBase);
        return ['base_path' => $base];
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
}
