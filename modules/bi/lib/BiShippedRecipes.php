<?php
declare(strict_types=1);

/**
 * Default KPI recipes shipped as JSON under modules/bi/recipes/.
 */
final class BiShippedRecipes
{
    public static function dir(): string
    {
        return base_path('modules/bi/recipes');
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        $ids = [];
        foreach (self::listFiles() as $id => $_path) {
            $ids[] = $id;
        }
        sort($ids);
        return $ids;
    }

    public static function isShipped(string $id): bool
    {
        $id = BiFamilyDefsRepository::safeId($id);
        return $id !== '' && isset(self::listFiles()[$id]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get(string $id): ?array
    {
        $id = BiFamilyDefsRepository::safeId($id);
        $files = self::listFiles();
        if ($id === '' || !isset($files[$id])) {
            return null;
        }
        $raw = file_get_contents($files[$id]);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $decoded['id'] = $id;
        return $decoded;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::ids() as $id) {
            $r = self::get($id);
            if ($r !== null) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * @return array<string,string> id => absolute path
     */
    private static function listFiles(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        $dir = self::dir();
        if (!is_dir($dir)) {
            return $cache;
        }
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $base = basename($path, '.json');
            $id = BiFamilyDefsRepository::safeId($base);
            if ($id !== '') {
                $cache[$id] = $path;
            }
        }
        return $cache;
    }
}
