<?php
declare(strict_types=1);

/**
 * Break-glass key for shared-hosting recovery when staff login is lost.
 * File: var/setup.key (gitignored; mode 0600). Generate via CLI setup-key.
 * TTL: file mtime older than TTL_SECONDS → auto-cleared on verify/health.
 */
final class SetupKey
{
    public const HEADER = 'HTTP_X_FREESAAS_SETUP_KEY';

    /** Default lifetime after generate (24h). */
    public const TTL_SECONDS = 86400;

    public static function path(): string
    {
        return base_path('var/setup.key');
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    public static function ageSeconds(): ?int
    {
        if (!self::exists()) {
            return null;
        }
        $mtime = @filemtime(self::path());
        if ($mtime === false) {
            return null;
        }
        return max(0, time() - $mtime);
    }

    public static function isExpired(): bool
    {
        $age = self::ageSeconds();
        if ($age === null) {
            return true;
        }
        return $age > self::TTL_SECONDS;
    }

    /** Delete key if missing/unreadable/expired. Returns true when a file was removed. */
    public static function purgeExpired(): bool
    {
        if (!self::exists()) {
            return false;
        }
        if (!self::isExpired()) {
            return false;
        }
        self::clear();
        return true;
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

    /**
     * Key from header or POST/JSON body only — never query (logs / Referer / history).
     */
    public static function providedKey(): string
    {
        $got = (string)($_SERVER[self::HEADER] ?? '');
        if ($got !== '') {
            return trim($got);
        }
        return trim((string)($_POST['setup_key'] ?? ''));
    }

    public static function verify(?string $provided = null): bool
    {
        self::purgeExpired();
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
