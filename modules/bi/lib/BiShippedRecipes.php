<?php
declare(strict_types=1);

/**
 * Default KPI recipes contributed via EventBus bi.recipes (owning modules).
 *
 * Listener appends to recipes[]:
 *   id (string)
 *   module (string)
 *   path (string) — absolute JSON file, OR
 *   recipe (array) — inline recipe
 */
final class BiShippedRecipes
{
    /** @var array<string,array<string,mixed>>|null id => recipe */
    private static $cache = null;

    /** @var array<string,string>|null id => module */
    private static $modules = null;

    public static function flush(): void
    {
        self::$cache = null;
        self::$modules = null;
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        $ids = array_keys(self::map());
        sort($ids);
        return $ids;
    }

    public static function isShipped(string $id): bool
    {
        $id = BiFamilyDefsRepository::safeId($id);
        return $id !== '' && isset(self::map()[$id]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get(string $id): ?array
    {
        $id = BiFamilyDefsRepository::safeId($id);
        if ($id === '') {
            return null;
        }
        $map = self::map();
        return $map[$id] ?? null;
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
     * Owning module for a shipped id (empty if unknown).
     */
    public static function moduleOf(string $id): string
    {
        self::map();
        return (string)(self::$modules[$id] ?? '');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function map(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        self::$modules = [];
        $payload = EventBus::emit('bi.recipes', ['recipes' => []]);
        $list = is_array($payload['recipes'] ?? null) ? $payload['recipes'] : [];
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = BiFamilyDefsRepository::safeId((string)($entry['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $module = (string)($entry['module'] ?? '');
            if (isset(self::$cache[$id])) {
                $prev = (string)(self::$modules[$id] ?? '?');
                throw new RuntimeException(
                    'bi.recipes conflict: ' . $id . ' (' . $prev . ' vs ' . ($module !== '' ? $module : '?') . ')'
                );
            }
            $recipe = null;
            if (isset($entry['recipe']) && is_array($entry['recipe'])) {
                $recipe = $entry['recipe'];
            } elseif (!empty($entry['path']) && is_string($entry['path']) && is_file($entry['path'])) {
                $raw = file_get_contents($entry['path']);
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $recipe = $decoded;
                    }
                }
            }
            if ($recipe === null) {
                continue;
            }
            $recipe['id'] = $id;
            self::$cache[$id] = $recipe;
            self::$modules[$id] = $module;
        }
        return self::$cache;
    }
}
