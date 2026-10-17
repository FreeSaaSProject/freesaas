<?php
declare(strict_types=1);

/**
 * Tool registry for the client MCP. Any module contributes tools through EventBus client_mcp.tools
 * (order-independent: collected on first use). Entry shape:
 *
 *   name         a-z0-9_ (MCP tool name, public contract)
 *   title, description, inputSchema, annotations   (LLM-facing, English)
 *   handler      callable(array $args): array — throw ClientMcpToolError for client-visible errors
 *   module       owning module id
 *   requires     list of module ids that must be enabled
 *   ready        optional callable(): bool (runtime readiness, e.g. booking engine active)
 *   core         true = cannot be switched off per host
 *   order        sort weight in tools/list
 *   instructions optional short hint appended to initialize.instructions
 */
final class ClientMcpTools
{
    /** @var array<string,array<string,mixed>>|null */
    private static $registry = null;

    /** @return array<string,array<string,mixed>> */
    public static function registry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }
        $payload = EventBus::emit('client_mcp.tools', ['tools' => []]);
        $out = [];
        foreach ((array)($payload['tools'] ?? []) as $raw) {
            $tool = is_array($raw) ? self::normalize($raw) : null;
            if ($tool === null) {
                continue;
            }
            if (isset($out[$tool['name']])) {
                error_log('client_mcp: duplicate tool ' . $tool['name'] . ' from ' . $tool['module'] . ' ignored');
                continue;
            }
            $out[$tool['name']] = $tool;
        }
        uasort($out, static function (array $a, array $b): int {
            return [$a['order'], $a['name']] <=> [$b['order'], $b['name']];
        });
        self::$registry = $out;
        return $out;
    }

    /**
     * Why a tool is not served on a host ('' = served).
     *
     * @param array<string,mixed> $tool
     * @param array<string,mixed> $host ClientMcpHost row
     */
    public static function unavailableReason(array $tool, array $host): string
    {
        foreach (array_merge([$tool['module']], $tool['requires']) as $mod) {
            if ($mod !== 'core' && !ModuleManager::isEnabled($mod)) {
                return 'module_off:' . $mod;
            }
        }
        if (empty($tool['core']) && in_array($tool['name'], (array)($host['settings']['disabled_tools'] ?? []), true)) {
            return 'disabled';
        }
        if (is_callable($tool['ready'])) {
            try {
                if (!($tool['ready'])()) {
                    return 'not_ready';
                }
            } catch (Throwable $e) {
                return 'not_ready';
            }
        }
        return '';
    }

    /**
     * @param array<string,mixed> $host
     * @return array<string,array<string,mixed>>
     */
    public static function availableFor(array $host): array
    {
        $out = [];
        foreach (self::registry() as $name => $tool) {
            if (self::unavailableReason($tool, $host) === '') {
                $out[$name] = $tool;
            }
        }
        return $out;
    }

    /**
     * MCP tools/list payload for a host.
     *
     * @param array<string,mixed> $host
     * @return list<array<string,mixed>>
     */
    public static function definitions(array $host): array
    {
        $list = [];
        foreach (self::availableFor($host) as $tool) {
            $def = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
            if ($tool['title'] !== '') {
                $def['title'] = $tool['title'];
            }
            if ($tool['annotations'] !== []) {
                $def['annotations'] = $tool['annotations'];
            }
            $list[] = $def;
        }
        return $list;
    }

    /** Non-core tools served on a host (health / settings). */
    public static function countProductTools(array $host): int
    {
        $n = 0;
        foreach (self::availableFor($host) as $tool) {
            if (empty($tool['core'])) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @param array<string,mixed> $host
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function call(array $host, string $name, array $args): array
    {
        $tool = self::availableFor($host)[$name] ?? null;
        if ($tool === null) {
            throw new ClientMcpToolError('unknown_tool', 'Unknown tool: ' . $name);
        }
        $res = ($tool['handler'])($args);
        return is_array($res) ? $res : ['ok' => true, 'result' => $res];
    }

    /** @param array<string,mixed> $host */
    public static function instructions(array $host): string
    {
        $parts = [];
        foreach (self::availableFor($host) as $tool) {
            $hint = trim((string)$tool['instructions']);
            if ($hint !== '') {
                $parts[] = $hint;
            }
        }
        return implode(' ', $parts);
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    private static function normalize(array $raw): ?array
    {
        $name = (string)($raw['name'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name) || !is_callable($raw['handler'] ?? null)) {
            return null;
        }
        $schema = is_array($raw['inputSchema'] ?? null) ? $raw['inputSchema'] : [];
        if (($schema['type'] ?? '') !== 'object') {
            $schema = ['type' => 'object'] + $schema;
        }
        if (!isset($schema['properties']) || $schema['properties'] === []) {
            $schema['properties'] = new stdClass();
        }
        $requires = [];
        foreach ((array)($raw['requires'] ?? []) as $mod) {
            $mod = (string)$mod;
            if (preg_match('/^[a-z][a-z0-9_]*$/', $mod)) {
                $requires[] = $mod;
            }
        }
        $module = (string)($raw['module'] ?? '');
        return [
            'name' => $name,
            'title' => (string)($raw['title'] ?? ''),
            'description' => (string)($raw['description'] ?? ''),
            'inputSchema' => $schema,
            'annotations' => is_array($raw['annotations'] ?? null) ? $raw['annotations'] : [],
            'handler' => $raw['handler'],
            'module' => preg_match('/^[a-z][a-z0-9_]*$/', $module) ? $module : 'client_mcp',
            'requires' => $requires,
            'ready' => is_callable($raw['ready'] ?? null) ? $raw['ready'] : null,
            'core' => !empty($raw['core']),
            'order' => (int)($raw['order'] ?? 100),
            'instructions' => (string)($raw['instructions'] ?? ''),
        ];
    }
}
