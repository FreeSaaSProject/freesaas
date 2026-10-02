<?php
declare(strict_types=1);

/**
 * Per-user menu item order (paths). Applied in ModuleManager::menuItems().
 */
final class MenuOrder
{
    public const PREF_KEY = 'menu.order';

    public static function resetDefaults(int $userId): void
    {
        if ($userId > 0 && class_exists('UserPrefsRepository', false)) {
            UserPrefsRepository::delete($userId, self::PREF_KEY);
        }
    }

    /**
     * @param list<array{path:string,title:string,group:string,num:int,module:string}> $items
     * @return list<array{path:string,title:string,group:string,num:int,module:string}>
     */
    public static function apply(array $items): array
    {
        $uid = Auth::check() ? Auth::userId() : 0;
        if ($uid < 1 || $items === [] || !class_exists('UserPrefsRepository', false)) {
            return $items;
        }
        $saved = UserPrefsRepository::getJson($uid, self::PREF_KEY);
        if (!is_array($saved) || $saved === []) {
            return $items;
        }

        $paths = [];
        foreach ($saved as $p) {
            $p = trim((string)$p);
            if ($p !== '') {
                $paths[] = $p;
            }
        }
        if ($paths === []) {
            return $items;
        }

        $byPath = [];
        foreach ($items as $item) {
            $byPath[(string)$item['path']] = $item;
        }

        $out = [];
        $seen = [];
        foreach ($paths as $path) {
            if (!isset($byPath[$path]) || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $out[] = $byPath[$path];
        }
        foreach ($items as $item) {
            $path = (string)$item['path'];
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @return list<array{path:string,title:string,group:string}>
     */
    public static function layoutForSettings(): array
    {
        $rows = [];
        foreach (ModuleManager::menuItems() as $item) {
            $rows[] = [
                'path' => (string)$item['path'],
                'title' => (string)$item['title'],
                'group' => (string)$item['group'],
            ];
        }
        return $rows;
    }

    /**
     * @param list<string> $orderedPaths
     */
    public static function save(int $userId, array $orderedPaths): void
    {
        if ($userId < 1 || !class_exists('UserPrefsRepository', false)) {
            return;
        }
        $valid = [];
        foreach (ModuleManager::menuItemsUnsorted() as $item) {
            $valid[(string)$item['path']] = true;
        }

        $out = [];
        $seen = [];
        foreach ($orderedPaths as $path) {
            $path = trim((string)$path);
            if ($path === '' || !isset($valid[$path]) || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $out[] = $path;
        }
        foreach ($valid as $path => $_) {
            if (isset($seen[$path])) {
                continue;
            }
            $out[] = $path;
        }
        UserPrefsRepository::setJson($userId, self::PREF_KEY, $out);
    }
}
