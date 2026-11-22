<?php
declare(strict_types=1);

/**
 * Event catalog: modules describe what they can notify about via EventBus `notify.catalog`.
 *
 * Event def: id, module, label (i18n key), entity, fields[{id,type,op[],options_from|options,label?}],
 * vars[], subjects[], anchor, outcomes[], default_template (i18n key).
 */
final class NotifyCatalog
{
    public const FIELD_TYPES = ['enum', 'bool', 'number', 'text'];
    public const OPS = ['eq', 'neq', 'in', 'nin', 'gt', 'gte', 'lt', 'lte', 'contains'];

    /** @var list<array<string,mixed>>|null */
    private static $cache = null;

    /** @return list<array<string,mixed>> */
    public static function collect(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $res = EventBus::emit('notify.catalog', ['events' => []]);
        $out = [];
        foreach ((array)($res['events'] ?? []) as $def) {
            $def = self::normalize($def);
            if ($def !== null) {
                $out[$def['id']] = $def;
            }
        }
        ksort($out);
        self::$cache = array_values($out);
        return self::$cache;
    }

    /** @return array<string,mixed>|null */
    public static function get(string $eventId): ?array
    {
        foreach (self::collect() as $def) {
            if ($def['id'] === $eventId) {
                return $def;
            }
        }
        return null;
    }

    /** Test hook / after late registration. */
    public static function reset(): void
    {
        self::$cache = null;
    }

    /**
     * Option list (value => label) for an enum field.
     *
     * @param array<string,mixed> $field
     * @return array<string,string>
     */
    public static function fieldOptions(array $field): array
    {
        $out = [];
        $from = (string)($field['options_from'] ?? '');
        if ($from !== '') {
            $res = EventBus::emit('notify.options', ['key' => $from, 'options' => []]);
            foreach ((array)($res['options'] ?? []) as $k => $v) {
                $out[(string)$k] = (string)$v;
            }
        }
        foreach ((array)($field['options'] ?? []) as $k => $v) {
            if (is_int($k)) {
                $out[(string)$v] = (string)$v;
            } else {
                $out[(string)$k] = (string)$v;
            }
        }
        return $out;
    }

    /**
     * @param mixed $def
     * @return array<string,mixed>|null
     */
    private static function normalize($def): ?array
    {
        if (!is_array($def)) {
            return null;
        }
        $id = trim((string)($def['id'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $id)) {
            return null;
        }
        $fields = [];
        foreach ((array)($def['fields'] ?? []) as $f) {
            if (!is_array($f) || !preg_match('/^[a-z][a-z0-9_]*$/', (string)($f['id'] ?? ''))) {
                continue;
            }
            $type = in_array((string)($f['type'] ?? ''), self::FIELD_TYPES, true) ? (string)$f['type'] : 'text';
            $ops = array_values(array_intersect((array)($f['op'] ?? ['eq']), self::OPS));
            $f['type'] = $type;
            $f['op'] = $ops !== [] ? $ops : ['eq'];
            $fields[(string)$f['id']] = $f;
        }
        return [
            'id' => $id,
            'module' => (string)($def['module'] ?? strtok($id, '.')),
            'label' => (string)($def['label'] ?? $id),
            'entity' => (string)($def['entity'] ?? ''),
            'fields' => array_values($fields),
            'vars' => array_values(array_map('strval', (array)($def['vars'] ?? []))),
            'subjects' => array_values(array_map('strval', (array)($def['subjects'] ?? []))),
            'anchor' => (string)($def['anchor'] ?? ''),
            'outcomes' => array_values(array_map('strval', (array)($def['outcomes'] ?? []))),
            'default_template' => (string)($def['default_template'] ?? ''),
        ];
    }

    /** @return array<string,string> id => translated label (for selects) */
    public static function labels(): array
    {
        $out = [];
        foreach (self::collect() as $def) {
            $out[$def['id']] = t($def['label']) . ' (' . $def['id'] . ')';
        }
        return $out;
    }
}
