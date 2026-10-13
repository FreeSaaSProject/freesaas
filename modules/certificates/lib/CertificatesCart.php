<?php
declare(strict_types=1);

/** Storefront session helper for certificate CODE on /cart. */
final class CertificatesCart
{
    private const SESSION_KEY = 'certificates_code';

    public static function code(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        return trim((string)($_SESSION[self::SESSION_KEY] ?? ''));
    }

    public static function setCode(string $code): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $code = trim($code);
        if ($code === '') {
            unset($_SESSION[self::SESSION_KEY]);
            return;
        }
        $_SESSION[self::SESSION_KEY] = $code;
    }

    public static function clear(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        unset($_SESSION[self::SESSION_KEY]);
    }
}
