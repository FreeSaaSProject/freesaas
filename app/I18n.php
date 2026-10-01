<?php
declare(strict_types=1);

/**
 * Lightweight UI localization: dotted keys, PHP array catalogs, ru/en.
 * UGC and DB status names are not translated here.
 */
final class I18n
{
    public const COOKIE = 'freesaas.locale';

    /** @var list<string> */
    private static $supported = ['ru', 'en'];

    /** @var string */
    private static $locale = 'ru';

    /** @var array<string, string>|null */
    private static $catalog = null;

    /** @var bool */
    private static $loaded = false;

    /** @return list<string> */
    public static function supported(): array
    {
        return self::$supported;
    }

    public static function normalize(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }
        $locale = strtolower(trim($locale));
        if ($locale === '') {
            return null;
        }
        // Accept en-US → en
        if (strpos($locale, '-') !== false) {
            $locale = explode('-', $locale, 2)[0];
        }
        if (strpos($locale, '_') !== false) {
            $locale = explode('_', $locale, 2)[0];
        }
        return in_array($locale, self::$supported, true) ? $locale : null;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function setLocale(string $locale): void
    {
        $n = self::normalize($locale);
        if ($n === null) {
            return;
        }
        if ($n !== self::$locale) {
            self::$locale = $n;
            self::$catalog = null;
            self::$loaded = false;
        }
    }

    /**
     * Resolve locale from session → cookie → config. Call after Auth::startSession().
     */
    public static function bootFromRequest(): void
    {
        $fromSession = self::normalize(isset($_SESSION['locale']) ? (string)$_SESSION['locale'] : null);
        if ($fromSession !== null) {
            self::setLocale($fromSession);
            return;
        }
        $fromCookie = self::normalize(isset($_COOKIE[self::COOKIE]) ? (string)$_COOKIE[self::COOKIE] : null);
        if ($fromCookie !== null) {
            self::setLocale($fromCookie);
            return;
        }
        $fromConfig = self::normalize((string)Config::get('app', 'locale', 'ru'));
        self::setLocale($fromConfig ?? 'ru');
    }

    public static function persistCookie(string $locale): void
    {
        $n = self::normalize($locale);
        if ($n === null) {
            return;
        }
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(self::COOKIE, $n, [
            'expires' => time() + 365 * 86400,
            'path' => '/',
            'secure' => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $n;
    }

    public static function applyUserChoice(string $locale, bool $saveToUser = true): void
    {
        $n = self::normalize($locale);
        if ($n === null) {
            return;
        }
        self::setLocale($n);
        $_SESSION['locale'] = $n;
        self::persistCookie($n);
        if ($saveToUser && Auth::check() && Database::columnExists('base_users', 'locale')) {
            $st = Database::pdo()->prepare('UPDATE base_users SET locale = ? WHERE id = ?');
            $st->execute([$n, Auth::userId()]);
        }
    }

    /**
     * Subset of keys for JS (crm.js). Pass explicit keys or prefix.
     *
     * @param list<string> $keys
     * @return array<string, string>
     */
    public static function jsDict(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = self::t($key);
        }
        return $out;
    }

    public static function t(string $key, array $replace = []): string
    {
        self::ensureLoaded();
        $text = self::$catalog[$key] ?? null;
        if ($text === null && self::$locale !== 'ru') {
            // Fallback catalog already merged as ru under missing keys; if still missing, use key.
            $text = $key;
        } elseif ($text === null) {
            $text = $key;
        }
        if ($replace === []) {
            return $text;
        }
        foreach ($replace as $name => $value) {
            $text = str_replace('{' . $name . '}', (string)$value, $text);
        }
        return $text;
    }

    /**
     * Format unix timestamp for UI.
     * @param 'date'|'datetime'|'daymonth' $style
     */
    public static function formatDate(int $ts, string $style = 'date'): string
    {
        if ($ts <= 0) {
            return '';
        }
        $loc = self::$locale;
        if ($style === 'datetime') {
            return $loc === 'en' ? date('Y-m-d H:i', $ts) : date('d.m.Y H:i', $ts);
        }
        if ($style === 'daymonth') {
            return $loc === 'en' ? date('M j', $ts) : date('d.m', $ts);
        }
        return $loc === 'en' ? date('Y-m-d', $ts) : date('d.m.Y', $ts);
    }

    private static function ensureLoaded(): void
    {
        if (self::$loaded && self::$catalog !== null) {
            return;
        }
        $locale = self::$locale;
        $merged = [];
        // Fallback: always load ru first when locale ≠ ru
        if ($locale !== 'ru') {
            $merged = self::loadLocaleFiles('ru');
        }
        $primary = self::loadLocaleFiles($locale);
        foreach ($primary as $k => $v) {
            $merged[$k] = $v;
        }
        self::$catalog = $merged;
        self::$loaded = true;
    }

    /** @return array<string, string> */
    private static function loadLocaleFiles(string $locale): array
    {
        $out = [];
        $files = [base_path('app/lang/' . $locale . '.php')];
        // Modules: prefer ModuleManager if booted, else scan directory
        if (class_exists('ModuleManager', false)) {
            // Load every discovered module catalog (incl. disabled) so module names/labels resolve on /modules.
            foreach (ModuleManager::all() as $id => $meta) {
                $dir = isset($meta['_dir']) ? (string)$meta['_dir'] : base_path('modules/' . $id);
                $files[] = $dir . '/lang/' . $locale . '.php';
            }
        } else {
            $dir = base_path('modules');
            if (is_dir($dir)) {
                foreach (scandir($dir) ?: [] as $id) {
                    if ($id === '.' || $id === '..') {
                        continue;
                    }
                    $files[] = $dir . '/' . $id . '/lang/' . $locale . '.php';
                }
            }
        }
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $data = include $file;
            if (!is_array($data)) {
                continue;
            }
            foreach ($data as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $out[$k] = $v;
                }
            }
        }
        return $out;
    }
}
