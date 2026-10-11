<?php
declare(strict_types=1);

/**
 * Lightweight UI localization: dotted keys, PHP array catalogs.
 * Supported locales are discovered from app/lang/*.php (+ optional app.locales ini).
 * UGC and DB status names are not translated here.
 */
final class I18n
{
    public const COOKIE = 'freesaas.locale';

    /** @var list<string>|null null = not discovered yet */
    private static $supported = null;

    /** @var string */
    private static $locale = 'ru';

    /** @var array<string, string>|null */
    private static $catalog = null;

    /** @var bool */
    private static $loaded = false;

    /** @return list<string> */
    public static function supported(): array
    {
        if (self::$supported === null) {
            self::refreshSupported();
        }
        return self::$supported ?? ['ru', 'en'];
    }

    /**
     * Rebuild supported locale list from filesystem and optional config.
     * Config `app.locales` (comma-separated) may pin/order the list; otherwise
     * every `app/lang/{code}.php` is included. Always keeps `ru` as fallback base.
     *
     * @return list<string>
     */
    public static function refreshSupported(): array
    {
        $found = [];
        $langDir = base_path('app/lang');
        if (is_dir($langDir)) {
            foreach (glob($langDir . '/*.php') ?: [] as $file) {
                $code = strtolower(basename($file, '.php'));
                if (preg_match('/^[a-z]{2}([_-][a-z]{2})?$/', $code)) {
                    // normalize en_US file names → en
                    if (strpos($code, '_') !== false) {
                        $code = explode('_', $code, 2)[0];
                    }
                    if (strpos($code, '-') !== false) {
                        $code = explode('-', $code, 2)[0];
                    }
                    $found[$code] = true;
                }
            }
        }

        $ordered = [];
        if (class_exists('Config', false)) {
            $cfg = trim((string)Config::get('app', 'locales', ''));
            if ($cfg !== '') {
                foreach (preg_split('/[\s,]+/', $cfg) ?: [] as $part) {
                    $part = strtolower(trim($part));
                    if ($part === '') {
                        continue;
                    }
                    if (strpos($part, '-') !== false) {
                        $part = explode('-', $part, 2)[0];
                    }
                    if (strpos($part, '_') !== false) {
                        $part = explode('_', $part, 2)[0];
                    }
                    if (isset($found[$part]) || is_file(base_path('app/lang/' . $part . '.php'))) {
                        $ordered[$part] = true;
                    }
                }
            }
        }
        if ($ordered === []) {
            foreach (array_keys($found) as $code) {
                $ordered[$code] = true;
            }
        }

        // Guarantee ru + en when their files exist; always keep ru for fallback semantics
        if (is_file(base_path('app/lang/ru.php'))) {
            $ordered = ['ru' => true] + $ordered;
        }
        if (is_file(base_path('app/lang/en.php')) && !isset($ordered['en'])) {
            $ordered['en'] = true;
        }
        if ($ordered === []) {
            $ordered = ['ru' => true, 'en' => true];
        }

        self::$supported = array_keys($ordered);
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
        return in_array($locale, self::supported(), true) ? $locale : null;
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
        self::refreshSupported();
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

    /**
     * Flat key→string map for one locale (app + all modules), without mutating current locale.
     *
     * @return array<string, string>
     */
    public static function collectCatalogKeys(string $locale): array
    {
        return self::loadLocaleFiles($locale);
    }

    /**
     * Compare locale catalogs / used t() keys / module.json labels.
     *
     * @return array{
     *   ok: bool,
     *   locales: list<string>,
     *   totals: array<string,int>,
     *   only_in: array<string, list<string>>,
     *   missing_used: array<string, array<string,string>>,
     *   module_json_missing: list<array{module:string,locale:string,key:string}>
     * }
     */
    public static function validateCatalogs(?string $localeFilter = null): array
    {
        $locales = self::supported();
        if ($localeFilter !== null && $localeFilter !== '') {
            $n = self::normalize($localeFilter);
            if ($n === null) {
                return [
                    'ok' => false,
                    'locales' => [],
                    'totals' => [],
                    'only_in' => [],
                    'missing_used' => [],
                    'module_json_missing' => [],
                    'error' => 'unsupported locale: ' . $localeFilter,
                ];
            }
            $locales = [$n];
            // Always need a base for parity when single locale ≠ ru
            if ($n !== 'ru' && !in_array('ru', $locales, true)) {
                array_unshift($locales, 'ru');
            }
        }

        $catalogs = [];
        $totals = [];
        foreach ($locales as $loc) {
            $catalogs[$loc] = self::collectCatalogKeys($loc);
            $totals[$loc] = count($catalogs[$loc]);
        }

        $base = $catalogs['ru'] ?? reset($catalogs);
        $baseLoc = isset($catalogs['ru']) ? 'ru' : (string)array_key_first($catalogs);
        $onlyIn = [];
        foreach ($catalogs as $loc => $keys) {
            if ($loc === $baseLoc) {
                continue;
            }
            $onlyBase = array_values(array_diff(array_keys($base), array_keys($keys)));
            $onlyLoc = array_values(array_diff(array_keys($keys), array_keys($base)));
            if ($onlyBase !== []) {
                $onlyIn[$baseLoc . '_not_' . $loc] = $onlyBase;
            }
            if ($onlyLoc !== []) {
                $onlyIn[$loc . '_not_' . $baseLoc] = $onlyLoc;
            }
        }

        $used = self::scanUsedTranslationKeys();
        $missingUsed = [];
        foreach ($catalogs as $loc => $keys) {
            $miss = [];
            foreach ($used as $k => $file) {
                if (!isset($keys[$k])) {
                    $miss[$k] = $file;
                }
            }
            if ($miss !== []) {
                $missingUsed[$loc] = $miss;
            }
        }

        $modMissing = [];
        foreach (ModuleManager::all() as $id => $meta) {
            $check = [];
            if (!empty($meta['name']) && is_string($meta['name'])) {
                $check[] = $meta['name'];
            }
            foreach ($meta['permissions'] ?? [] as $p) {
                if (is_array($p) && !empty($p['label']) && is_string($p['label'])) {
                    $check[] = $p['label'];
                }
            }
            foreach ($meta['menu'] ?? [] as $m) {
                if (!is_array($m)) {
                    continue;
                }
                if (!empty($m['title']) && is_string($m['title'])) {
                    $check[] = $m['title'];
                }
                if (!empty($m['group']) && is_string($m['group'])) {
                    $check[] = $m['group'];
                }
            }
            foreach ($check as $k) {
                foreach ($catalogs as $loc => $keys) {
                    if (!isset($keys[$k])) {
                        $modMissing[] = ['module' => (string)$id, 'locale' => $loc, 'key' => $k];
                    }
                }
            }
        }

        $ok = $onlyIn === [] && $missingUsed === [] && $modMissing === [];
        return [
            'ok' => $ok,
            'locales' => array_keys($catalogs),
            'totals' => $totals,
            'only_in' => $onlyIn,
            'missing_used' => $missingUsed,
            'module_json_missing' => $modMissing,
        ];
    }

    /**
     * @return array<string, string> key => filepath
     */
    public static function scanUsedTranslationKeys(): array
    {
        $used = [];
        $root = realpath(base_path(''));
        if ($root === false) {
            return $used;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $p = $f->getPathname();
            if (strpos($p, '/lang/') !== false || strpos($p, '/vendor/') !== false) {
                continue;
            }
            if (!preg_match('/\.(php|js)$/', $p)) {
                continue;
            }
            $rel = str_replace($root, '', $p);
            if (preg_match('#/\.#', $rel)) {
                continue;
            }
            // Scaffold generator file embeds dynamic translation key templates (not catalog keys)
            if (substr($p, -strlen('ModuleScaffold.php')) === 'ModuleScaffold.php') {
                continue;
            }
            $c = file_get_contents($p);
            if ($c === false) {
                continue;
            }
            if (preg_match_all("/\\b(?:t|te|tj)\\(\\s*['\"]([^'\"]+)['\"]/", $c, $m)) {
                foreach ($m[1] as $k) {
                    // Skip dynamic prefixes: t('core.news.type.' . $id) — scanner sees trailing dot
                    if ($k === '' || substr($k, -1) === '.') {
                        continue;
                    }
                    // Skip non-dotted placeholders / variables mistaken as literals
                    if (strpos($k, '.') === false) {
                        continue;
                    }
                    $used[$k] = $p;
                }
            }
        }
        return $used;
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
     * Subset of keys for JS (core.js / module scripts). Pass explicit keys or prefix.
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
