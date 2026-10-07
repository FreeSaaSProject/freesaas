<?php
declare(strict_types=1);

/**
 * Structural validation of modules for agents/CI.
 * Exit semantics for CLI: 0 ok, 1 has errors (warnings alone → 0 with warnings listed).
 */
final class ModuleValidator
{
    /**
     * @return array{ok:bool,module:string,errors:list<string>,warnings:list<string>}
     */
    public static function validate(string $moduleId): array
    {
        $errors = [];
        $warnings = [];
        $meta = ModuleManager::get($moduleId);
        if ($meta === null) {
            return [
                'ok' => false,
                'module' => $moduleId,
                'errors' => ['module not discovered'],
                'warnings' => [],
            ];
        }

        $dir = (string)($meta['_dir'] ?? base_path('modules/' . $moduleId));
        if (!is_file($dir . '/module.json')) {
            $errors[] = 'missing module.json';
        }
        if (!is_file($dir . '/bootstrap.php')) {
            $errors[] = 'missing bootstrap.php';
        }

        foreach (I18n::supported() as $loc) {
            $lang = $dir . '/lang/' . $loc . '.php';
            if (!is_file($lang)) {
                // ru/en are mandatory for product UI; other locales warn if any catalog exists elsewhere
                if ($loc === 'ru' || $loc === 'en') {
                    $errors[] = 'missing lang/' . $loc . '.php';
                } else {
                    $warnings[] = 'missing lang/' . $loc . '.php';
                }
            }
        }

        foreach (['id', 'name', 'version', 'type'] as $field) {
            if (empty($meta[$field]) || !is_string($meta[$field])) {
                $errors[] = "manifest missing string field: {$field}";
            }
        }
        if (($meta['id'] ?? '') !== $moduleId) {
            $errors[] = 'manifest id mismatch directory/id';
        }

        if (!isset($meta['requires']) || !is_array($meta['requires'])) {
            $warnings[] = 'manifest missing requires (expected object)';
        }
        if (!isset($meta['optionalRequires']) || !is_array($meta['optionalRequires'])) {
            $warnings[] = 'manifest missing optionalRequires (expected object)';
        }

        // Permissions / menu labels must look like i18n keys
        $name = (string)($meta['name'] ?? '');
        if ($name !== '' && strpos($name, '.') === false) {
            $warnings[] = 'name should be an i18n key (module.key)';
        }
        foreach ($meta['permissions'] ?? [] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $label = (string)($p['label'] ?? '');
            if ($label !== '' && strpos($label, '.') === false) {
                $errors[] = 'permission label must be i18n key: ' . $label;
            }
            if ($label !== '' && !self::catalogHasKey($label)) {
                $errors[] = 'permission label missing in catalogs: ' . $label;
            }
        }
        foreach ($meta['menu'] ?? [] as $m) {
            if (!is_array($m)) {
                continue;
            }
            foreach (['title', 'group'] as $fk) {
                $v = (string)($m[$fk] ?? '');
                if ($v === '') {
                    continue;
                }
                if (strpos($v, '.') === false) {
                    $errors[] = "menu.{$fk} must be i18n key: {$v}";
                } elseif (!self::catalogHasKey($v)) {
                    $errors[] = "menu.{$fk} missing in catalogs: {$v}";
                }
            }
        }
        if ($name !== '' && strpos($name, '.') !== false && !self::catalogHasKey($name)) {
            $errors[] = 'module name key missing in catalogs: ' . $name;
        }

        // Hooks: dotted event names only (no spaces / prose)
        foreach (['publish', 'subscribe'] as $side) {
            $list = $meta['hooks'][$side] ?? [];
            if (!is_array($list)) {
                $errors[] = "hooks.{$side} must be an array";
                continue;
            }
            foreach ($list as $ev) {
                if (!is_string($ev)) {
                    $errors[] = "hooks.{$side} entry must be string";
                    continue;
                }
                if (!self::isEventName($ev)) {
                    $errors[] = "hooks.{$side} invalid event name (use dotted ids only): {$ev}";
                }
            }
        }

        // routes.pages vs registered routes (only for enabled/booted modules)
        $declared = [];
        foreach ($meta['routes']['pages'] ?? [] as $p) {
            if (is_string($p) && $p !== '') {
                $declared[$p] = true;
            }
        }
        $registered = [];
        foreach (ModuleManager::routes() as $path => $def) {
            if (($def['module'] ?? '') === $moduleId) {
                $registered[(string)$path] = true;
            }
        }
        if ($registered !== []) {
            foreach (array_keys($declared) as $path) {
                if (!isset($registered[$path])) {
                    $warnings[] = "routes.pages path not registered in bootstrap: {$path}";
                }
            }
            foreach (array_keys($registered) as $path) {
                if (!isset($declared[$path])) {
                    $warnings[] = "registered route missing from routes.pages: {$path}";
                }
            }
        } elseif ($declared !== [] && empty($meta['_enabled'])) {
            $warnings[] = 'module disabled — route registration not checked';
        }

        // bulkActions (optional machine index)
        if (isset($meta['bulkActions'])) {
            if (!is_array($meta['bulkActions'])) {
                $errors[] = 'bulkActions must be an array';
            } else {
                foreach ($meta['bulkActions'] as $i => $row) {
                    if (!is_array($row) || empty($row['list_id']) || empty($row['action'])) {
                        $errors[] = "bulkActions[{$i}] needs list_id and action";
                    }
                }
            }
        }

        // agent.ops — product JSON surface (see docs/agents/module-agent-api.md)
        if (isset($meta['agent'])) {
            if (!is_array($meta['agent'])) {
                $errors[] = 'agent must be an object';
            } else {
                $ops = $meta['agent']['ops'] ?? null;
                if ($ops !== null && !is_array($ops)) {
                    $errors[] = 'agent.ops must be an array';
                } elseif (is_array($ops)) {
                    $seenIds = [];
                    foreach ($ops as $i => $row) {
                        $op = class_exists('AgentHttp', false) ? AgentHttp::normalizeOp($row) : null;
                        if ($op === null) {
                            $errors[] = "agent.ops[{$i}] needs id (dotted), method, path starting with /";
                            continue;
                        }
                        if (isset($seenIds[$op['id']])) {
                            $errors[] = 'agent.ops duplicate id: ' . $op['id'];
                        }
                        $seenIds[$op['id']] = true;
                        if ($declared !== [] && !isset($declared[$op['path']])) {
                            $warnings[] = 'agent.ops path missing from routes.pages: ' . $op['path'];
                        }
                        if ($registered !== [] && !isset($registered[$op['path']])) {
                            $warnings[] = 'agent.ops path not registered in bootstrap: ' . $op['path'];
                        }
                    }
                }
            }
        }


        // Tables vs migrations heuristic
        $tables = [];
        foreach ($meta['tables'] ?? [] as $t) {
            if (is_string($t) && $t !== '') {
                $tables[] = $t;
            }
        }
        $migDir = $dir . '/migrations';
        if ($tables !== [] && is_dir($migDir)) {
            $blob = '';
            foreach (glob($migDir . '/*.php') ?: [] as $file) {
                $blob .= (string)file_get_contents($file);
            }
            foreach ($tables as $table) {
                if ($blob !== '' && stripos($blob, $table) === false) {
                    $warnings[] = "table {$table} listed in manifest but not found in migrations/*.php text";
                }
            }
        } elseif ($tables !== [] && !is_dir($migDir)) {
            $warnings[] = 'tables listed but migrations/ directory missing';
        }

        // Dependency cycle (simple DFS from this module through hard requires)
        $cycle = self::findCycle($moduleId, [], []);
        if ($cycle !== null) {
            $errors[] = 'requires cycle: ' . implode(' → ', $cycle);
        }

        return [
            'ok' => $errors === [],
            'module' => $moduleId,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<array{ok:bool,module:string,errors:list<string>,warnings:list<string>}>
     */
    public static function validateAll(): array
    {
        $out = [];
        foreach (ModuleManager::all() as $id => $_) {
            $out[] = self::validate((string)$id);
        }
        return $out;
    }

    public static function isEventName(string $ev): bool
    {
        // dotted tokens, optional leading namespace segments; no spaces
        return (bool)preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/i', $ev);
    }

    private static function catalogHasKey(string $key): bool
    {
        static $ru = null;
        if ($ru === null) {
            $ru = I18n::collectCatalogKeys('ru');
        }
        return isset($ru[$key]);
    }

    /**
     * @param list<string> $stack
     * @param array<string,bool> $visiting
     * @return list<string>|null
     */
    private static function findCycle(string $id, array $stack, array $visiting): ?array
    {
        if (isset($visiting[$id])) {
            $idx = array_search($id, $stack, true);
            $cycle = $idx === false ? $stack : array_slice($stack, (int)$idx);
            $cycle[] = $id;
            return $cycle;
        }
        $meta = ModuleManager::get($id);
        if ($meta === null) {
            return null;
        }
        $visiting[$id] = true;
        $stack[] = $id;
        $requires = $meta['requires'] ?? [];
        if (is_array($requires)) {
            foreach ($requires as $depId => $_) {
                $depId = (string)$depId;
                if ($depId === '' || $depId === 'core') {
                    continue;
                }
                $hit = self::findCycle($depId, $stack, $visiting);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }
        return null;
    }
}
