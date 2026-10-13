<?php
declare(strict_types=1);

/**
 * Admin vs storefront role for the same FreeSaaS package.
 *
 * Resolution order:
 * 1. Constant FREESAAS_ROLE (set by site.php / index.php before bootstrap)
 * 2. Config app.role (admin|storefront)
 * 3. Default: admin
 */
final class AppRuntime
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_STOREFRONT = 'storefront';

    /** @var string|null */
    private static $role = null;

    public static function boot(): void
    {
        if (self::$role !== null) {
            return;
        }
        $raw = '';
        if (defined('FREESAAS_ROLE')) {
            $raw = (string)constant('FREESAAS_ROLE');
        } elseif (class_exists('Config', false)) {
            $raw = (string)Config::get('app', 'role', self::ROLE_ADMIN);
        }
        self::$role = self::normalize($raw);
    }

    public static function role(): string
    {
        if (self::$role === null) {
            self::boot();
        }
        return self::$role ?? self::ROLE_ADMIN;
    }

    public static function isStorefront(): bool
    {
        return self::role() === self::ROLE_STOREFRONT;
    }

    public static function isAdmin(): bool
    {
        return self::role() === self::ROLE_ADMIN;
    }

    /** Public site host without scheme (for links / login redirect). */
    public static function publicHost(): string
    {
        if (class_exists('SitesHostContext', false) || class_exists('SitesHostContext')) {
            $ctx = SitesHostContext::host();
            if ($ctx !== '') {
                return $ctx;
            }
        }
        $host = trim((string)Config::get('app', 'public_host', ''));
        if ($host === '') {
            $host = trim((string)Config::get('sites', 'public_host', ''));
        }
        if ($host === '') {
            $host = trim((string)Config::get('likespa', 'mothersite', ''));
        }
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = rtrim($host, '/');
        if (($p = strpos($host, '/')) !== false) {
            $host = substr($host, 0, $p);
        }
        return strtolower($host);
    }

    /** Admin host without scheme (storefront → staff login redirect). */
    public static function adminHost(): string
    {
        $host = trim((string)Config::get('app', 'admin_host', ''));
        if ($host === '') {
            $host = trim((string)Config::get('sites', 'admin_host', ''));
        }
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        return rtrim($host, '/');
    }

    public static function adminBaseUrl(): string
    {
        $host = self::adminHost();
        if ($host === '') {
            $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
            if (($p = strpos($host, ':')) !== false) {
                $host = substr($host, 0, $p);
            }
        }
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = rtrim($host, '/');
        if ($host === '') {
            return '';
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $local = (bool)preg_match('/^(localhost|127\.|.*\.(local|hm|test))$/i', $host)
            || strpos($host, 'dev') !== false;
        $scheme = ($https || !$local) ? 'https' : 'http';

        return $scheme . '://' . $host;
    }

    /** Absolute admin UI URL for a path (e.g. /wellness/food-diary). */
    public static function adminUrl(string $path = '/'): string
    {
        $path = trim($path);
        if ($path === '') {
            $path = '/';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        $base = self::adminBaseUrl();
        if ($base === '') {
            return $path;
        }

        return $base . $path;
    }

    private static function normalize(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if ($raw === self::ROLE_STOREFRONT || $raw === 'site' || $raw === 'public') {
            return self::ROLE_STOREFRONT;
        }
        return self::ROLE_ADMIN;
    }
}
