<?php
declare(strict_types=1);

/** Persist bank mTLS files under cfg/modules/banks-certs/ (outside web). */
final class BanksCertStorage
{
    public const KINDS = ['cert', 'key', 'ca'];

    private const EXT_ALLOW = [
        'cert' => ['cer', 'crt', 'pem', 'p12', 'pfx'],
        'key' => ['key', 'pem'],
        'ca' => ['cer', 'crt', 'pem'],
    ];

    private const MAX_BYTES = 512 * 1024;

    public static function dir(): string
    {
        $dir = base_path('cfg/modules/banks-certs');
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException(t('banks.err.certs_dir'));
            }
        }
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
        return $dir;
    }

    /** Absolute path stored in ini for curl. */
    public static function configKey(string $provider, string $kind): string
    {
        $p = BanksConfig::normalizeProvider($provider);
        $kind = strtolower(trim($kind));
        if ($p === '' || !in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException(t('banks.err.certs_kind'));
        }
        return $p . '_mtls_' . $kind;
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{path:string,config_key:string,bytes:int}
     */
    public static function saveUpload(string $provider, string $kind, array $file): array
    {
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(t('banks.err.certs_upload'));
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException(t('banks.err.certs_upload'));
        }
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException(t('banks.err.certs_size'));
        }
        $bytes = (string)file_get_contents($tmp);
        $name = (string)($file['name'] ?? 'upload.bin');
        return self::saveBytes($provider, $kind, $bytes, $name);
    }

    /**
     * @return array{path:string,config_key:string,bytes:int}
     */
    public static function saveBase64(string $provider, string $kind, string $b64, string $filename = ''): array
    {
        $raw = trim($b64);
        if (strpos($raw, 'base64,') !== false) {
            $raw = substr($raw, strpos($raw, 'base64,') + 7);
        }
        $bytes = base64_decode($raw, true);
        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException(t('banks.err.certs_base64'));
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException(t('banks.err.certs_size'));
        }
        return self::saveBytes($provider, $kind, $bytes, $filename !== '' ? $filename : ($kind . '.bin'));
    }

    /**
     * @return array{path:string,config_key:string,bytes:int}
     */
    public static function saveBytes(string $provider, string $kind, string $bytes, string $filename): array
    {
        $p = BanksConfig::normalizeProvider($provider);
        $kind = strtolower(trim($kind));
        if ($p === '' || !in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException(t('banks.err.certs_kind'));
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = self::EXT_ALLOW[$kind] ?? [];
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            throw new InvalidArgumentException(t('banks.err.certs_ext', [
                'kind' => $kind,
                'ext' => implode(', ', $allowed),
            ]));
        }
        $dir = self::dir();
        $safeName = $p . '_mtls_' . $kind . '.' . $ext;
        $path = $dir . '/' . $safeName;
        if (@file_put_contents($path, $bytes) === false) {
            throw new RuntimeException(t('banks.err.certs_write'));
        }
        @chmod($path, 0600);
        $key = self::configKey($p, $kind);
        Config::set('banks', $key, $path);
        return ['path' => $path, 'config_key' => $key, 'bytes' => strlen($bytes)];
    }

    public static function integrationDir(int $integrationId): string
    {
        if ($integrationId < 1) {
            throw new InvalidArgumentException('integration id');
        }
        $dir = self::dir() . '/int_' . $integrationId;
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException(t('banks.err.certs_dir'));
            }
        }
        return $dir;
    }

    /**
     * Save mTLS file for a DB integration; updates base_bank_integrations path columns.
     *
     * @return array{path:string,kind:string,bytes:int}
     */
    public static function saveBytesForIntegration(int $integrationId, string $kind, string $bytes, string $filename): array
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException(t('banks.err.certs_kind'));
        }
        if (strlen($bytes) < 1 || strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException(t('banks.err.certs_size'));
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = self::EXT_ALLOW[$kind] ?? [];
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            throw new InvalidArgumentException(t('banks.err.certs_ext', [
                'kind' => $kind,
                'ext' => implode(', ', $allowed),
            ]));
        }
        $dir = self::integrationDir($integrationId);
        $path = $dir . '/' . $kind . '.' . $ext;
        if (@file_put_contents($path, $bytes) === false) {
            throw new RuntimeException(t('banks.err.certs_write'));
        }
        @chmod($path, 0600);
        $col = 'mtls_' . $kind;
        BanksIntegrationRepository::update($integrationId, [$col => $path]);
        return ['path' => $path, 'kind' => $kind, 'bytes' => strlen($bytes)];
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{path:string,kind:string,bytes:int}
     */
    public static function saveUploadForIntegration(int $integrationId, string $kind, array $file): array
    {
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(t('banks.err.certs_upload'));
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException(t('banks.err.certs_upload'));
        }
        return self::saveBytesForIntegration(
            $integrationId,
            $kind,
            (string)file_get_contents($tmp),
            (string)($file['name'] ?? ($kind . '.bin'))
        );
    }

    /**
     * @return array{path:string,kind:string,bytes:int}
     */
    public static function saveBase64ForIntegration(int $integrationId, string $kind, string $b64, string $filename = ''): array
    {
        $raw = trim($b64);
        if (strpos($raw, 'base64,') !== false) {
            $raw = substr($raw, strpos($raw, 'base64,') + 7);
        }
        $bytes = base64_decode($raw, true);
        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException(t('banks.err.certs_base64'));
        }
        return self::saveBytesForIntegration(
            $integrationId,
            $kind,
            $bytes,
            $filename !== '' ? $filename : ($kind . '.pem')
        );
    }

    /** @return array<string,array{path:string,exists:bool,readable:bool,bytes:int}> */
    public static function statusForProvider(string $provider): array
    {
        $p = BanksConfig::normalizeProvider($provider);
        $out = [];
        foreach (self::KINDS as $kind) {
            $key = $p . '_mtls_' . $kind;
            $path = trim((string)Config::get('banks', $key, ''));
            $exists = $path !== '' && is_file($path);
            $out[$kind] = [
                'path' => $path,
                'exists' => $exists,
                'readable' => $exists && is_readable($path),
                'bytes' => $exists ? (int)filesize($path) : 0,
            ];
        }
        return $out;
    }

    /** @return array<string,array{path:string,exists:bool,readable:bool,bytes:int}> */
    public static function statusForIntegration(int $integrationId): array
    {
        $row = BanksIntegrationRepository::find($integrationId, true);
        $out = [];
        foreach (self::KINDS as $kind) {
            $path = trim((string)($row['mtls_' . $kind] ?? ''));
            $exists = $path !== '' && is_file($path);
            $out[$kind] = [
                'path' => $path,
                'exists' => $exists,
                'readable' => $exists && is_readable($path),
                'bytes' => $exists ? (int)filesize($path) : 0,
            ];
        }
        return $out;
    }
}
