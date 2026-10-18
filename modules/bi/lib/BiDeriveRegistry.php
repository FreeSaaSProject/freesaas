<?php
declare(strict_types=1);

/**
 * Contrib derive expand steps (EventBus bi.derive_steps). Engine core stays free of product logic.
 *
 * Listener appends to steps[]:
 *   id (string) — recipe derive key, e.g. expand_shift_lanes
 *   module (string) — owning module id
 *   handler (callable) — function(list<row>, array $cfg): list<row>
 *   legend (array) — optional meta for GET /bi/legend
 */
final class BiDeriveRegistry
{
    /** @var array<string,array<string,mixed>>|null */
    private static $cache = null;

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * @return array<string,array<string,mixed>> id => step
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $payload = EventBus::emit('bi.derive_steps', ['steps' => []]);
        $list = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];
        $out = [];
        foreach ($list as $step) {
            if (!is_array($step)) {
                continue;
            }
            $id = trim((string)($step['id'] ?? ''));
            if ($id === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
                throw new RuntimeException('bi.derive_steps: invalid id');
            }
            if (isset($out[$id])) {
                $prev = (string)($out[$id]['module'] ?? '?');
                $cur = (string)($step['module'] ?? '?');
                throw new RuntimeException('bi.derive_steps conflict: ' . $id . ' (' . $prev . ' vs ' . $cur . ')');
            }
            if (!isset($step['handler']) || !is_callable($step['handler'])) {
                throw new RuntimeException('bi.derive_steps: handler required for ' . $id);
            }
            $out[$id] = $step;
        }
        self::$cache = $out;
        return self::$cache;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get(string $id): ?array
    {
        $all = self::all();
        return $all[$id] ?? null;
    }

    public static function has(string $id): bool
    {
        return self::get($id) !== null;
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    /**
     * @param array<string,mixed> $derive
     */
    public static function expandKeyIn(array $derive): ?string
    {
        foreach (self::ids() as $id) {
            if (($derive[$id] ?? null) !== null && ($derive[$id] ?? false) !== false) {
                return $id;
            }
        }
        // Legacy key still blocks fuse even if workweeks off.
        if (($derive['expand_shift_lanes'] ?? null) !== null
            && ($derive['expand_shift_lanes'] ?? false) !== false
        ) {
            return 'expand_shift_lanes';
        }
        return null;
    }

    /**
     * @return array<string,mixed> id => legend meta
     */
    public static function legendMeta(): array
    {
        $out = [];
        foreach (self::all() as $id => $step) {
            $meta = is_array($step['legend'] ?? null) ? $step['legend'] : [];
            $meta['module'] = (string)($step['module'] ?? '');
            $out[$id] = $meta;
        }
        return $out;
    }
}
