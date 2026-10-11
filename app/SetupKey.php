<?php
declare(strict_types=1);

/**
 * Break-glass key for shared-hosting recovery when staff login is lost.
 * File: var/setup.key (gitignored; mode 0600). Generate via CLI setup-key.
 */
final class SetupKey
{
    public const HEADER = 'HTTP_X_FREESAAS_SETUP_KEY';

    public static function path(): string
    {
        return base_path('var/setup.key');
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /**
     * Write a new 64-char hex key (32 bytes). Returns the key once.
     */
    public static function generate(): string
    {
        $dir = dirname(self::path());
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create var/ for setup.key');
        }
        $key = bin2hex(random_bytes(32));
        if (file_put_contents(self::path(), $key . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Cannot write ' . self::path());
        }
        @chmod(self::path(), 0600);
        return $key;
    }

    public static function clear(): void
    {
        $p = self::path();
        if (is_file($p)) {
            @unlink($p);
        }
    }

    public static function providedKey(): string
    {
        $got = (string)($_SERVER[self::HEADER] ?? '');
        if ($got !== '') {
            return trim($got);
        }
        return trim((string)($_GET['setup_key'] ?? $_POST['setup_key'] ?? ''));
    }

    public static function verify(?string $provided = null): bool
    {
        $provided = trim((string)($provided ?? self::providedKey()));
        if ($provided === '' || !self::exists()) {
            return false;
        }
        $raw = @file_get_contents(self::path());
        if ($raw === false) {
            return false;
        }
        $expected = trim($raw);
        if ($expected === '' || strlen($expected) < 32) {
            return false;
        }
        return hash_equals($expected, $provided);
    }
}
