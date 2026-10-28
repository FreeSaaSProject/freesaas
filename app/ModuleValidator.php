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

        // uninstall.strategy: drop_owned (default) | leave_tables
        if (isset($meta['uninstall'])) {
            if (!is_array($meta['uninstall'])) {
                $errors[] = 'uninstall must be an object';
            } else {
                $us = strtolower(trim((string)($meta['uninstall']['strategy'] ?? '')));
                if ($us !== '' && $us !== 'drop_owned' && $us !== 'leave_tables') {
                    $warnings[] = 'uninstall.strategy unknown (expected drop_owned|leave_tables): ' . $us;
                }
            }
        }

        // setup.steps — required on product modules (may be empty []); core exempt
        if ($moduleId !== 'core') {
            if (!isset($meta['setup'])) {
                $warnings[] = 'manifest missing setup (expected object with steps[])';
            } elseif (!is_array($meta['setup'])) {
                $errors[] = 'setup must be an object';
            } else {
                $steps = $meta['setup']['steps'] ?? null;
                if (!is_array($steps)) {
                    $errors[] = 'setup.steps must be an array';
                } else {
                    foreach ($steps as $i => $step) {
                        if (!is_array($step)) {
                            $errors[] = 'setup.steps[' . $i . '] must be an object';
                            continue;
                        }
                        $sid = trim((string)($step['id'] ?? ''));
                        if ($sid === '') {
                            $errors[] = 'setup.steps[' . $i . '].id required';
                        }
                        foreach (['title', 'description', 'ask_user'] as $fk) {
                            $v = trim((string)($step[$fk] ?? ''));
                            if ($v === '') {
                                continue;
                            }
                            if (strpos($v, '.') === false) {
                                $errors[] = "setup.steps.{$sid}.{$fk} must be i18n key";
                            } elseif (!self::catalogHasKey($v) || !self::catalogHasKeyIn('en', $v)) {
                                $errors[] = "setup.steps.{$sid}.{$fk} missing in catalogs: {$v}";
                            }
                        }
                        $choices = $step['choices'] ?? null;
                        if (is_array($choices)) {
                            $hasRec = false;
                            foreach ($choices as $c) {
                                if (!is_array($c)) {
                                    continue;
                                }
                                if (!empty($c['recommended'])) {
                                    $hasRec = true;
                                }
                                $cl = trim((string)($c['label'] ?? ''));
                                if ($cl !== '' && strpos($cl, '.') !== false
                                    && $cl !== 'core.modules.setup.choice.defer'
                                    && (!self::catalogHasKey($cl) || !self::catalogHasKeyIn('en', $cl))
                                ) {
                                    $errors[] = "setup.steps.{$sid} choice label missing: {$cl}";
                                }
                            }
                            if ($choices !== [] && !$hasRec) {
                                $warnings[] = "setup.steps.{$sid} has no recommended choice";
                            }
                        }
                    }
                }
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

        // agent.ops / agent.docs — product JSON + staff help runbooks
        if (isset($meta['agent'])) {
            if (!is_array($meta['agent'])) {
                $errors[] = 'agent must be an object';
            } else {
                if (array_key_exists('docs', $meta['agent'])) {
                    $docs = $meta['agent']['docs'];
                    if (!is_string($docs) || trim($docs) === '') {
                        $errors[] = 'agent.docs must be a non-empty string path under docs/agents/';
                    } else {
                        $rel = str_replace('\\', '/', trim($docs));
                        $rel = ltrim($rel, '/');
                        if (strpos($rel, '..') !== false
                            || !preg_match('#^docs/agents/[a-z0-9][a-z0-9._-]*\.md$#i', $rel)
                        ) {
                            $errors[] = 'agent.docs must be docs/agents/<file>.md (no ..)';
                        } elseif (!is_file(base_path($rel))) {
                            $warnings[] = 'agent.docs file missing: ' . $rel;
                        }
                    }
                }
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
                if (array_key_exists('relations', $meta['agent'])) {
                    self::validateRelations($meta['agent']['relations'], $errors, $warnings);
                }
            }
        }


        // Tables: exclusive ownership for uninstall DROP — not “tables this module uses”.
        $tables = [];
        foreach ($meta['tables'] ?? [] as $t) {
            if (is_string($t) && $t !== '') {
                $tables[] = $t;
            }
        }
        foreach ($tables as $table) {
            $others = ModuleManager::otherOwnersOfTable($moduleId, $table);
            if ($others !== []) {
                $errors[] = "table {$table} is also owned by "
                    . implode(', ', $others)
                    . ' — module.json tables[] must list exclusive ownership only'
                    . ' (ALTER/usage of foreign tables: omit from tables[], document in docs/TABLE_MAP.md)';
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

    /**
     * agent.relations[] (assistant plan consequences). Op ids are checked against every module
     * on disk — a disabled module is fine, a typo is not.
     *
     * @param mixed $relations
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private static function validateRelations($relations, array &$errors, array &$warnings): void
    {
        if (!is_array($relations)) {
            $errors[] = 'agent.relations must be an array';
            return;
        }
        $known = [];
        foreach (ModuleManager::all() as $meta) {
            foreach ((array)($meta['agent']['ops'] ?? []) as $op) {
                if (is_array($op) && isset($op['id']) && is_string($op['id'])) {
                    $known[$op['id']] = true;
                }
            }
        }
        foreach ($relations as $i => $rel) {
            $label = 'agent.relations[' . $i . ']';
            if (!is_array($rel)) {
                $errors[] = $label . ' must be an object';
                continue;
            }
            $when = $rel['when'] ?? null;
            $check = $rel['check'] ?? null;
            if (!is_array($when) || $when === [] || !is_string($check) || trim($check) === '') {
                $errors[] = $label . ' needs when[] and check';
                continue;
            }
            $refs = array_merge($when, [$check], (array)($rel['suggest'] ?? []));
            $from = isset($rel['from']) && is_string($rel['from']) ? trim($rel['from']) : '';
            if ($from !== '') {
                $refs[] = $from;
            }
            foreach ($refs as $ref) {
                if (!is_string($ref) || !isset($known[$ref])) {
                    $warnings[] = $label . ' unknown op: ' . (is_string($ref) ? $ref : json_encode($ref));
                }
            }
            $usesFrom = false;
            foreach ((array)($rel['args'] ?? []) as $tpl) {
                if (is_string($tpl) && strpos($tpl, '{from.') === 0) {
                    $usesFrom = true;
                }
            }
            foreach ((array)($rel['if'] ?? []) as $cond) {
                if ($cond === 'changed') {
                    $usesFrom = true;
                }
            }
            if ($usesFrom && $from === '') {
                $warnings[] = $label . ' uses {from.*} / "changed" without from';
            }
            foreach (['hint', 'notice'] as $k) {
                $key = isset($rel[$k]) && is_string($rel[$k]) ? trim($rel[$k]) : '';
                if ($key !== '' && !self::catalogHasKey($key)) {
                    $warnings[] = $label . ' ' . $k . ' key missing in lang: ' . $key;
                }
            }
        }
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
