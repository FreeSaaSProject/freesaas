<?php
declare(strict_types=1);

/**
 * Home dashboard: catalog of available widgets + per-user layout prefs.
 *
 * Catalog entry shape (via EventBus core.dashboard.catalog):
 *   id, title, module?, default_order (int), default_on (bool),
 *   settings? => [ key => {type, min?, max?, default, label} ]
 *
 * Widget instance shape (via EventBus core.dashboard.widgets):
 *   id, title, body?, html?, tone?, href?, size? (sm|md|lg)
 *
 * During widget emit, call setContext($layout) so builders can read
 * isEnabled() / setting().
 */
final class DashboardWidgets
{
    public const PREF_KEY = 'dashboard.layout';

    /** @var list<array{id:string,enabled:bool,settings:array<string,mixed>}>|null */
    private static $context = null;

    public static function resetDefaults(int $userId): void
    {
        if ($userId > 0) {
            UserPrefsRepository::delete($userId, self::PREF_KEY);
        }
    }

    /**
     * @param list<array{id:string,enabled:bool,settings?:array<string,mixed>}> $layout
     */
    public static function setContext(array $layout): void
    {
        self::$context = $layout;
    }

    public static function clearContext(): void
    {
        self::$context = null;
    }

    public static function isEnabled(string $widgetId): bool
    {
        if (self::$context === null) {
            return true;
        }
        foreach (self::$context as $row) {
            if (($row['id'] ?? '') === $widgetId) {
                return !empty($row['enabled']);
            }
        }
        return false;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function setting(string $widgetId, string $key, $default = null)
    {
        if (self::$context === null) {
            return $default;
        }
        foreach (self::$context as $row) {
            if (($row['id'] ?? '') !== $widgetId) {
                continue;
            }
            $settings = $row['settings'] ?? [];
            if (is_array($settings) && array_key_exists($key, $settings)) {
                return $settings[$key];
            }
            return $default;
        }
        return $default;
    }

    /**
     * @return list<array{id:string,title:string,module:string,default_order:int,default_on:bool,settings:array<string,array<string,mixed>>}>
     */
    public static function catalog(): array
    {
        $raw = EventBus::emit('core.dashboard.catalog', []);
        if (!is_array($raw)) {
            return [];
        }
        $byId = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '' || isset($byId[$id])) {
                continue;
            }
            $byId[$id] = [
                'id' => $id,
                'title' => (string)($row['title'] ?? $id),
                'module' => (string)($row['module'] ?? ''),
                'default_order' => (int)($row['default_order'] ?? 100),
                'default_on' => !empty($row['default_on']),
                'settings' => self::normalizeSchema($row['settings'] ?? []),
            ];
        }
        $items = array_values($byId);
        usort($items, static function (array $a, array $b): int {
            if ($a['default_order'] === $b['default_order']) {
                return strcmp($a['id'], $b['id']);
            }
            return $a['default_order'] <=> $b['default_order'];
        });
        return $items;
    }

    /**
     * @param list<array{id:string,title:string,module:string,default_order:int,default_on:bool,settings:array}> $catalog
     * @return list<array{id:string,title:string,module:string,enabled:bool,settings:array<string,mixed>,settings_schema:array}>
     */
    public static function resolveLayout(int $userId, array $catalog): array
    {
        $meta = [];
        foreach ($catalog as $row) {
            $meta[$row['id']] = $row;
        }
        if ($meta === []) {
            return [];
        }

        $saved = $userId > 0 ? UserPrefsRepository::getJson($userId, self::PREF_KEY) : null;
        $layout = [];
        $seen = [];

        if (is_array($saved)) {
            foreach ($saved as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string)($row['id'] ?? ''));
                if ($id === '' || !isset($meta[$id]) || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $schema = $meta[$id]['settings'];
                $layout[] = [
                    'id' => $id,
                    'title' => $meta[$id]['title'],
                    'module' => $meta[$id]['module'],
                    'enabled' => array_key_exists('enabled', $row)
                        ? !empty($row['enabled'])
                        : !empty($meta[$id]['default_on']),
                    'settings' => self::applySettings($schema, is_array($row['settings'] ?? null) ? $row['settings'] : []),
                    'settings_schema' => $schema,
                ];
            }
        }

        foreach ($catalog as $row) {
            $id = $row['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $schema = $row['settings'];
            $layout[] = [
                'id' => $id,
                'title' => $row['title'],
                'module' => $row['module'],
                'enabled' => !empty($row['default_on']),
                'settings' => self::applySettings($schema, []),
                'settings_schema' => $schema,
            ];
        }

        return $layout;
    }

    /**
     * @param list<string> $orderedIds
     * @param list<string> $enabledIds
     * @param array<string,array<string,mixed>> $settingsById
     * @param list<array{id:string,settings?:array,...}> $catalog
     */
    public static function saveLayout(
        int $userId,
        array $orderedIds,
        array $enabledIds,
        array $catalog,
        array $settingsById = []
    ): void {
        if ($userId < 1) {
            return;
        }
        $valid = [];
        $schemas = [];
        foreach ($catalog as $row) {
            $valid[$row['id']] = true;
            $schemas[$row['id']] = self::normalizeSchema($row['settings'] ?? []);
        }
        $enabledMap = [];
        foreach ($enabledIds as $id) {
            $id = trim((string)$id);
            if ($id !== '' && isset($valid[$id])) {
                $enabledMap[$id] = true;
            }
        }

        $out = [];
        $seen = [];
        foreach ($orderedIds as $id) {
            $id = trim((string)$id);
            if ($id === '' || !isset($valid[$id]) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $rawSettings = is_array($settingsById[$id] ?? null) ? $settingsById[$id] : [];
            $entry = [
                'id' => $id,
                'enabled' => !empty($enabledMap[$id]),
            ];
            $normalized = self::applySettings($schemas[$id], $rawSettings);
            if ($normalized !== []) {
                $entry['settings'] = $normalized;
            }
            $out[] = $entry;
        }
        foreach ($catalog as $row) {
            $id = $row['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $rawSettings = is_array($settingsById[$id] ?? null) ? $settingsById[$id] : [];
            $entry = [
                'id' => $id,
                'enabled' => !empty($enabledMap[$id]),
            ];
            $normalized = self::applySettings($schemas[$id] ?? [], $rawSettings);
            if ($normalized !== []) {
                $entry['settings'] = $normalized;
            }
            $out[] = $entry;
        }

        UserPrefsRepository::setJson($userId, self::PREF_KEY, $out);
    }

    /**
     * @param list<array<string,mixed>> $widgets
     * @param list<array{id:string,enabled:bool,...}> $layout
     * @return list<array<string,mixed>>
     */
    public static function filterAndSort(array $widgets, array $layout): array
    {
        $byId = [];
        foreach ($widgets as $w) {
            if (!is_array($w)) {
                continue;
            }
            $id = trim((string)($w['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $byId[$id] = $w;
        }

        $out = [];
        foreach ($layout as $row) {
            if (empty($row['enabled'])) {
                continue;
            }
            $id = (string)$row['id'];
            if (!isset($byId[$id])) {
                continue;
            }
            $out[] = $byId[$id];
        }
        return $out;
    }

    /**
     * @param list<string> $orderedIds
     * @return list<string>
     */
    public static function moveId(array $orderedIds, string $id, string $dir): array
    {
        $ids = [];
        foreach ($orderedIds as $x) {
            $x = trim((string)$x);
            if ($x !== '') {
                $ids[] = $x;
            }
        }
        $idx = array_search($id, $ids, true);
        if ($idx === false) {
            return $ids;
        }
        $swapWith = $dir === 'up' ? $idx - 1 : $idx + 1;
        if ($swapWith < 0 || $swapWith >= count($ids)) {
            return $ids;
        }
        $tmp = $ids[$idx];
        $ids[$idx] = $ids[$swapWith];
        $ids[$swapWith] = $tmp;
        return $ids;
    }

    /**
     * Shared limit setting schema for list-style widgets.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function limitSettingSchema(int $default = 8, int $min = 1, int $max = 40): array
    {
        return [
            'limit' => [
                'type' => 'int',
                'min' => $min,
                'max' => $max,
                'default' => $default,
                'label' => 'core.dashboard.setting.limit',
            ],
        ];
    }

    /**
     * @param mixed $raw
     * @return array<string,array<string,mixed>>
     */
    private static function normalizeSchema($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $def) {
            $key = trim((string)$key);
            if ($key === '' || !is_array($def)) {
                continue;
            }
            $type = (string)($def['type'] ?? 'int');
            if ($type !== 'int') {
                continue;
            }
            $out[$key] = [
                'type' => 'int',
                'min' => (int)($def['min'] ?? 1),
                'max' => (int)($def['max'] ?? 100),
                'default' => (int)($def['default'] ?? 8),
                'label' => (string)($def['label'] ?? $key),
            ];
        }
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $schema
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private static function applySettings(array $schema, array $raw): array
    {
        $out = [];
        foreach ($schema as $key => $def) {
            $val = $raw[$key] ?? $def['default'];
            if (($def['type'] ?? '') === 'int') {
                $n = (int)$val;
                $min = (int)$def['min'];
                $max = (int)$def['max'];
                if ($n < $min) {
                    $n = $min;
                }
                if ($n > $max) {
                    $n = $max;
                }
                $out[$key] = $n;
            }
        }
        return $out;
    }
}
