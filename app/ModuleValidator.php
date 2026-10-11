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

        // description: optional i18n key (same convention as name)
        $description = $meta['description'] ?? null;
        if ($description === null || $description === '') {
            $warnings[] = 'manifest missing description (expected i18n key module.description)';
        } elseif (!is_string($description)) {
            $errors[] = 'description must be an i18n key string';
        } elseif (strpos($description, '.') === false) {
            $errors[] = 'description must be an i18n key (module.key), not prose: ' . $description;
        } else {
            if (!self::catalogHasKey($description)) {
                $errors[] = 'description key missing in catalogs: ' . $description;
            }
            if (!self::catalogHasKeyIn('en', $description)) {
                $errors[] = 'description key missing in en catalog: ' . $description;
            }
        }

        $summaryKey = $moduleId . '.module.summary';
        if (self::catalogHasKey($summaryKey) || self::catalogHasKeyIn('en', $summaryKey)) {
            if (!self::catalogHasKey($summaryKey)) {
                $errors[] = 'summary key missing in ru catalog: ' . $summaryKey;
            }
            if (!self::catalogHasKeyIn('en', $summaryKey)) {
                $errors[] = 'summary key missing in en catalog: ' . $summaryKey;
            }
        }

        // catalog block: categories[] / tags / screenshots
        if (!isset($meta['catalog'])) {
            $warnings[] = 'manifest missing catalog (categories/tags recommended)';
        } elseif (!is_array($meta['catalog'])) {
            $errors[] = 'catalog must be an object';
        } else {
            $cat = ModuleCatalog::parseCatalog($meta);
            if ($cat['categories_declared'] === []) {
                $warnings[] = 'catalog.categories empty';
            }
            foreach ($cat['categories_declared'] as $c) {
                if (!ModuleCatalog::isValidCategory($c)) {
                    $errors[] = 'catalog.categories not in allowlist: ' . $c;
                }
            }
            if ($cat['maturity_declared'] === '') {
                $warnings[] = 'catalog.maturity missing (expected alpha|beta|release; default beta)';
            } elseif (!ModuleCatalog::isValidMaturity($cat['maturity_declared'])) {
                $errors[] = 'catalog.maturity not in allowlist: ' . $cat['maturity_declared'];
            }
            foreach ($cat['tags'] as $tag) {
                if (!ModuleCatalog::isValidTag($tag)) {
                    $errors[] = 'catalog.tags not in allowlist: ' . $tag;
                }
            }
            foreach ($cat['screenshots'] as $i => $shot) {
                $file = (string)$shot['file'];
                $norm = str_replace('\\', '/', $file);
                if ($norm === '' || strpos($norm, '..') !== false || strpos($norm, 'assets/catalog/') !== 0) {
                    $errors[] = "catalog.screenshots[{$i}].file must be under assets/catalog/";
                    continue;
                }
                $abs = $dir . '/' . $norm;
                if (!is_file($abs)) {
                    $warnings[] = "catalog.screenshots[{$i}] file missing: {$norm}";
                }
                $cap = (string)$shot['caption'];
                if ($cap !== '') {
                    if (strpos($cap, '.') === false) {
                        $errors[] = "catalog.screenshots[{$i}].caption must be i18n key";
                    } elseif (!self::catalogHasKey($cap)) {
                        $errors[] = "catalog.screenshots[{$i}].caption missing in catalogs: {$cap}";
                    }
                }
            }
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

        // Soft-dep honesty: isEnabled / foreign page|view requires vs requires+optionalRequires
        $soft = self::scanSoftDeps($moduleId, $dir, $meta);
        foreach ($soft['errors'] as $msg) {
            $errors[] = $msg;
        }
        foreach ($soft['warnings'] as $msg) {
            $warnings[] = $msg;
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
     * Scan module PHP for ModuleManager::isEnabled and foreign pages/views requires.
     *
     * @param array<string,mixed> $meta
     * @return array{errors:list<string>,warnings:list<string>}
     */
    public static function scanSoftDeps(string $moduleId, string $dir, array $meta): array
    {
        $errors = [];
        $warnings = [];
        $hard = [];
        foreach ($meta['requires'] ?? [] as $depId => $_) {
            $depId = (string)$depId;
            if ($depId !== '') {
                $hard[$depId] = true;
            }
        }
        $soft = [];
        foreach ($meta['optionalRequires'] ?? [] as $depId => $_) {
            $depId = (string)$depId;
            if ($depId !== '') {
                $soft[$depId] = true;
            }
        }
        $declared = $hard + $soft;
        $undeclaredEnabled = [];
        $foreign = [];

        if (!is_dir($dir)) {
            return ['errors' => $errors, 'warnings' => $warnings];
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || substr($file->getFilename(), -4) !== '.php') {
                continue;
            }
            $path = $file->getPathname();
            // Scaffold templates embed example t() / isEnabled strings — not runtime.
            if (strpos($path, 'ModuleScaffold') !== false) {
                continue;
            }
            $src = (string)@file_get_contents($path);
            if ($src === '') {
                continue;
            }
            $rel = $path;
            $root = base_path('');
            if ($root !== '' && strpos($rel, $root) === 0) {
                $rel = ltrim(substr($rel, strlen($root)), '/\\');
            }

            if (preg_match_all("/ModuleManager::isEnabled\(\s*['\"]([a-z0-9_]+)['\"]\s*\)/", $src, $m)) {
                foreach ($m[1] as $dep) {
                    if ($dep === $moduleId || $dep === 'core') {
                        continue;
                    }
                    if (!isset($declared[$dep])) {
                        $undeclaredEnabled[$dep][$rel] = true;
                    }
                }
            }

            if (preg_match_all(
                "/(?:require|include)(?:_once)?\s*\(?\s*base_path\(\s*['\"]modules\/([a-z0-9_]+)\/(pages|views)\/([^'\"]+)['\"]/",
                $src,
                $m,
                PREG_SET_ORDER
            )) {
                foreach ($m as $hit) {
                    $other = (string)$hit[1];
                    if ($other === $moduleId) {
                        continue;
                    }
                    $kind = (string)$hit[2];
                    $target = $kind . '/' . (string)$hit[3];
                    $foreign[] = [
                        'other' => $other,
                        'kind' => $kind,
                        'target' => $target,
                        'file' => $rel,
                        'hard' => isset($hard[$other]),
                        'soft' => isset($soft[$other]),
                    ];
                }
            }
        }

        ksort($undeclaredEnabled);
        foreach ($undeclaredEnabled as $dep => $files) {
            $sample = array_slice(array_keys($files), 0, 3);
            $warnings[] = 'isEnabled("' . $dep . '") without requires/optionalRequires'
                . ' (add optionalRequires.' . $dep . ') in ' . implode(', ', $sample);
        }

        foreach ($foreign as $row) {
            $other = $row['other'];
            $msg = 'foreign ' . $row['kind'] . ' require of ' . $other . '/' . $row['target']
                . ' via ' . $row['file'];
            if ($row['kind'] === 'pages') {
                if ($row['hard']) {
                    $warnings[] = $msg . ' (hard require OK; prefer shared lib/EventBus for new code)';
                } else {
                    $errors[] = $msg . ' — declare in requires or stop page-level cross-require';
                }
            } else {
                // views
                if ($row['hard'] || $row['soft']) {
                    $warnings[] = $msg . ' (declared; prefer EventBus embed for new code)';
                } else {
                    $errors[] = $msg . ' — declare in requires/optionalRequires';
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
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
        return self::catalogHasKeyIn('ru', $key);
    }

    private static function catalogHasKeyIn(string $locale, string $key): bool
    {
        static $cache = [];
        if (!isset($cache[$locale])) {
            $cache[$locale] = I18n::collectCatalogKeys($locale);
        }
        return isset($cache[$locale][$key]);
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
