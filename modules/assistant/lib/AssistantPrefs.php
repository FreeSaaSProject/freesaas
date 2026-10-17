<?php
declare(strict_types=1);

/**
 * Per-user FAB prefs in base_user_prefs (not instance-wide).
 */
final class AssistantPrefs
{
    public const KEY_FAB_OFF = 'assistant.fab_off';

    public const KEY_HIDDEN_PATHS = 'assistant.fab_hidden_paths';

    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $parsed = parse_url($path, PHP_URL_PATH);
        if (is_string($parsed) && $parsed !== '') {
            $path = $parsed;
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }

    public static function isFabOff(int $userId): bool
    {
        if ($userId < 1 || !class_exists('UserPrefsRepository', false)) {
            return false;
        }
        return UserPrefsRepository::get($userId, self::KEY_FAB_OFF) === '1';
    }

    public static function setFabOff(int $userId, bool $off): void
    {
        if ($userId < 1 || !class_exists('UserPrefsRepository', false)) {
            return;
        }
        if ($off) {
            UserPrefsRepository::set($userId, self::KEY_FAB_OFF, '1');
            return;
        }
        UserPrefsRepository::delete($userId, self::KEY_FAB_OFF);
    }

    /**
     * @return list<string>
     */
    public static function listHiddenPaths(int $userId): array
    {
        if ($userId < 1 || !class_exists('UserPrefsRepository', false)) {
            return [];
        }
        $raw = UserPrefsRepository::getJson($userId, self::KEY_HIDDEN_PATHS);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $p) {
            $n = self::normalizePath((string)$p);
            if ($n === '' || isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            $out[] = $n;
        }
        sort($out);
        return $out;
    }

    public static function isPathHidden(int $userId, string $path): bool
    {
        $n = self::normalizePath($path);
        if ($n === '') {
            return false;
        }
        return in_array($n, self::listHiddenPaths($userId), true);
    }

    public static function hidePath(int $userId, string $path): void
    {
        $n = self::normalizePath($path);
        if ($userId < 1 || $n === '' || !class_exists('UserPrefsRepository', false)) {
            return;
        }
        $list = self::listHiddenPaths($userId);
        if (!in_array($n, $list, true)) {
            $list[] = $n;
            sort($list);
        }
        UserPrefsRepository::setJson($userId, self::KEY_HIDDEN_PATHS, $list);
    }

    public static function unhidePath(int $userId, string $path): void
    {
        $n = self::normalizePath($path);
        if ($userId < 1 || $n === '' || !class_exists('UserPrefsRepository', false)) {
            return;
        }
        $list = array_values(array_filter(
            self::listHiddenPaths($userId),
            static function (string $p) use ($n): bool {
                return $p !== $n;
            }
        ));
        if ($list === []) {
            UserPrefsRepository::delete($userId, self::KEY_HIDDEN_PATHS);
            return;
        }
        UserPrefsRepository::setJson($userId, self::KEY_HIDDEN_PATHS, $list);
    }

    /**
     * @param list<string> $paths
     */
    public static function unhidePaths(int $userId, array $paths): void
    {
        foreach ($paths as $p) {
            self::unhidePath($userId, (string)$p);
        }
    }

    /**
     * Full-page /assistant never gets a FAB; respect personal off + per-path hide.
     */
    public static function shouldShowFab(int $userId, string $path): bool
    {
        $n = self::normalizePath($path);
        if ($n === '/assistant' || strpos($n, '/assistant/') === 0) {
            return false;
        }
        if (self::isFabOff($userId)) {
            return false;
        }
        if (self::isPathHidden($userId, $n)) {
            return false;
        }
        return true;
    }
}
