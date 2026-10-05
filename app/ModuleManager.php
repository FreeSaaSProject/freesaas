<?php
declare(strict_types=1);

final class ModuleManager
{
    /** @var array<string, array> */
    private static $modules = [];

    /** @var array<string, array> */
    private static $routes = [];

    /** @var array<string, int> Rail group order; filled via registerMenuGroup() — never hardcode product keys here. */
    private static $menuGroupWeights = [];

    /** @var array<string, string> Default landing path per group key (optional 3rd arg of registerMenuGroup). */
    private static $menuGroupHomes = [];

    public static function discover(): void
    {
        $root = base_path('modules');
        if (!is_dir($root)) {
            return;
        }
        foreach (glob($root . '/*/module.json') ?: [] as $manifestPath) {
            $json = file_get_contents($manifestPath);
            $data = json_decode((string)$json, true);
            if (!is_array($data) || empty($data['id'])) {
                continue;
            }
            $id = (string)$data['id'];
            $dir = dirname($manifestPath);
            $data['_dir'] = $dir;
            $data['_enabled'] = Config::moduleEnabled($id);
            self::$modules[$id] = $data;
        }
        ksort(self::$modules);
    }

    public static function bootEnabled(): void
    {
        foreach (self::$modules as $id => $meta) {
            if (empty($meta['_enabled'])) {
                continue;
            }
            $block = self::unmetRequiredDeps($id);
            if ($block !== null) {
                error_log("[ModuleManager] Module '$id' blocked: $block");
                self::$modules[$id]['_enabled'] = false;
                self::$modules[$id]['_block_reason'] = $block;
                continue;
            }
            $boot = $meta['_dir'] . '/bootstrap.php';
            if (is_file($boot)) {
                require_once $boot;
            }
        }
    }

    /** @return array<string, array> */
    public static function all(): array
    {
        return self::$modules;
    }

    public static function isEnabled(string $id): bool
    {
        return !empty(self::$modules[$id]['_enabled']);
    }

    public static function get(string $id): ?array
    {
        return self::$modules[$id] ?? null;
    }

    /**
     * Enable or disable a discovered module. Writes cfg/config.ini and syncs registry.
     * Enabling requires all hard deps (present, enabled, version) and runs that module's schema migrations.
     * Disabling refuses if other enabled modules require it.
     *
     * @return list<array{module:string,id:string,file:string,status:string,message?:string}>
     */
    public static function setEnabled(string $id, bool $enabled): array
    {
        if ($id === 'core') {
            throw new RuntimeException(t('app.module.err.core_locked'));
        }
        if (!isset(self::$modules[$id])) {
            throw new RuntimeException(t('app.module.err.not_found', ['id' => $id]));
        }

        if ($enabled) {
            $block = self::unmetRequiredDeps($id, true);
            if ($block !== null) {
                throw new RuntimeException($block);
            }
        } else {
            $dependents = self::enabledDependents($id);
            if ($dependents) {
                throw new RuntimeException(
                    t('app.module.err.has_dependents', ['id' => $id, 'modules' => implode(', ', $dependents)])
                );
            }
        }

        $report = [];
        if ($enabled) {
            // Schema first (no module-enabled gate) so connect installs tables, then flip flag.
            $report = SchemaMigrator::runModule($id);
            foreach ($report as $row) {
                if (($row['status'] ?? '') === 'error') {
                    throw new RuntimeException(
                        t('app.module.err.migration', [
                            'module' => (string)$row['module'],
                            'id' => (string)$row['id'],
                            'message' => (string)($row['message'] ?? 'error'),
                        ])
                    );
                }
            }
        }

        Config::setModuleEnabled($id, $enabled);
        self::$modules[$id]['_enabled'] = $enabled;
        if ($enabled) {
            unset(self::$modules[$id]['_block_reason']);
        }
        self::syncRegistry();
        return $report;
    }

    /**
     * Full status for UI: deps, version checks, who depends on this module.
     *
     * @return array{
     *   enabled: bool,
     *   config_enabled: bool,
     *   can_toggle: bool,
     *   can_enable: bool,
     *   can_disable: bool,
     *   block_reason: ?string,
     *   requires: list<array{id:string,constraint:string,optional:bool,present:bool,enabled:bool,version:?string,ok:bool,detail:string}>,
     *   required_by: list<string>,
     *   deps_ok: bool,
     *   migrations: list<array{id:string,status:string}>,
     *   migrations_pending: int
     * }
     */
    public static function status(string $id): array
    {
        $meta = self::$modules[$id] ?? null;
        if ($meta === null) {
            throw new InvalidArgumentException("Unknown module: $id");
        }

        $requires = self::collectDeps($id);
        $depsOk = true;
        foreach ($requires as $row) {
            if (!$row['optional'] && !$row['ok']) {
                $depsOk = false;
                break;
            }
        }

        $configEnabled = Config::moduleEnabled($id);
        $runtimeEnabled = !empty($meta['_enabled']);
        $block = $meta['_block_reason'] ?? null;
        if ($configEnabled && !$runtimeEnabled && $block === null) {
            $block = self::unmetRequiredDeps($id);
        }

        $requiredBy = self::enabledDependents($id);
        $isCore = ($id === 'core');
        $migrations = SchemaMigrator::listModule($id);
        $pending = 0;
        foreach ($migrations as $m) {
            if ($m['status'] === 'pending') {
                $pending++;
            }
        }

        return [
            'enabled' => $runtimeEnabled,
            'config_enabled' => $configEnabled,
            'can_toggle' => !$isCore,
            'can_enable' => !$isCore && !$configEnabled && $depsOk,
            'can_disable' => !$isCore && $configEnabled && $requiredBy === [],
            'block_reason' => $block,
            'requires' => $requires,
            'required_by' => $requiredBy,
            'deps_ok' => $depsOk,
            'migrations' => $migrations,
            'migrations_pending' => $pending,
        ];
    }

    /**
     * @return list<array{id:string,constraint:string,optional:bool,present:bool,enabled:bool,version:?string,ok:bool,detail:string}>
     */
    public static function collectDeps(string $id): array
    {
        $meta = self::$modules[$id] ?? [];
        $rows = [];
        foreach (['requires' => false, 'optionalRequires' => true] as $key => $optional) {
            $map = $meta[$key] ?? [];
            if (!is_array($map)) {
                continue;
            }
            foreach ($map as $depId => $constraint) {
                $depId = (string)$depId;
                $constraint = trim((string)$constraint);
                $present = isset(self::$modules[$depId]);
                $depMeta = $present ? self::$modules[$depId] : null;
                $version = $present ? (string)($depMeta['version'] ?? '') : null;
                $enabled = $present && (
                    $depId === 'core'
                        ? true
                        : Config::moduleEnabled($depId)
                );

                $ok = true;
                $detail = '';
                if (!$present) {
                    $ok = false;
                    $detail = t('app.module.dep.missing');
                } elseif (!$enabled) {
                    $ok = false;
                    $detail = t('app.module.dep.disabled');
                } elseif ($constraint !== '' && $version !== null && $version !== '') {
                    if (!self::satisfiesConstraint($version, $constraint)) {
                        $ok = false;
                        $detail = t('app.module.dep.version_mismatch', ['version' => $version, 'constraint' => $constraint]);
                    } else {
                        $detail = "v$version · $constraint";
                    }
                } elseif ($constraint !== '') {
                    $detail = $version ? "v$version · $constraint" : $constraint;
                } elseif ($version) {
                    $detail = "v$version";
                } else {
                    $detail = 'ok';
                }

                $satisfied = $ok;
                if ($optional && !$ok) {
                    $detail = t('app.module.dep.optional', ['detail' => $detail]);
                }

                $rows[] = [
                    'id' => $depId,
                    'constraint' => $constraint,
                    'optional' => $optional,
                    'present' => $present,
                    'enabled' => $enabled,
                    'version' => $version !== '' ? $version : null,
                    'ok' => $optional ? true : $ok,
                    'satisfied' => $satisfied,
                    'detail' => $detail,
                ];
            }
        }
        return $rows;
    }

    /** First unmet hard dependency message, or null if ok. */
    public static function unmetRequiredDeps(string $id, bool $forEnable = false): ?string
    {
        foreach (self::collectDeps($id) as $row) {
            if ($row['optional']) {
                continue;
            }
            // When checking runtime boot, dep must be config-enabled (collectDeps already checks Config).
            // For enable UI, same rule: deps must already be on.
            if (!$row['present']) {
                return t('app.module.req.missing', ['id' => (string)$row['id']]);
            }
            if (!$row['enabled']) {
                return t('app.module.req.disabled', ['id' => (string)$row['id']]);
            }
            if ($row['constraint'] !== '' && $row['version'] !== null) {
                if (!self::satisfiesConstraint($row['version'], $row['constraint'])) {
                    return t('app.module.req.version', [
                        'id' => (string)$row['id'],
                        'constraint' => (string)$row['constraint'],
                        'version' => (string)$row['version'],
                    ]);
                }
            } elseif ($row['constraint'] !== '' && ($row['version'] === null || $row['version'] === '')) {
                return t('app.module.req.version_unknown', [
                    'id' => (string)$row['id'],
                    'constraint' => (string)$row['constraint'],
                ]);
            }
        }
        return null;
    }

    /** @return list<string> */
    public static function enabledDependents(string $id): array
    {
        $list = [];
        foreach (self::$modules as $otherId => $meta) {
            if ($otherId === $id) {
                continue;
            }
            if ($otherId !== 'core' && !Config::moduleEnabled($otherId)) {
                continue;
            }
            $req = $meta['requires'] ?? [];
            if (is_array($req) && array_key_exists($id, $req)) {
                $list[] = $otherId;
            }
        }
        sort($list);
        return $list;
    }

    /**
     * Semver-ish: supports >=, >, <=, <, =, ==, ^, ~ and bare version (=).
     */
    public static function satisfiesConstraint(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        $versionNorm = self::normalizeVersion($version);
        if ($versionNorm === null) {
            return false;
        }

        if (preg_match('/^(>=|<=|>|<|==|=|\^|~)\s*(.+)$/', $constraint, $m)) {
            $op = $m[1];
            $right = self::normalizeVersion($m[2]);
            if ($right === null) {
                return false;
            }
            if ($op === '^') {
                $upper = [$right[0] + 1, 0, 0];
                return self::cmpVersion($versionNorm, $right) >= 0
                    && self::cmpVersion($versionNorm, $upper) < 0;
            }
            if ($op === '~') {
                $upper = [$right[0], $right[1] + 1, 0];
                return self::cmpVersion($versionNorm, $right) >= 0
                    && self::cmpVersion($versionNorm, $upper) < 0;
            }
            $cmp = self::cmpVersion($versionNorm, $right);
            switch ($op) {
                case '>=': return $cmp >= 0;
                case '>':  return $cmp > 0;
                case '<=': return $cmp <= 0;
                case '<':  return $cmp < 0;
                case '=':
                case '==': return $cmp === 0;
            }
        }

        $right = self::normalizeVersion($constraint);
        if ($right === null) {
            return false;
        }
        return self::cmpVersion($versionNorm, $right) === 0;
    }

    /** @return array{0:int,1:int,2:int}|null */
    private static function normalizeVersion(string $v): ?array
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $v, $m)) {
            return null;
        }
        return [(int)$m[1], (int)($m[2] ?? 0), (int)($m[3] ?? 0)];
    }

    /** @param array{0:int,1:int,2:int} $a @param array{0:int,1:int,2:int} $b */
    private static function cmpVersion(array $a, array $b): int
    {
        for ($i = 0; $i < 3; $i++) {
            if ($a[$i] < $b[$i]) {
                return -1;
            }
            if ($a[$i] > $b[$i]) {
                return 1;
            }
        }
        return 0;
    }

    public static function registerRoute(string $path, array $def): void
    {
        self::$routes[$path] = $def;
    }

    /** @return array<string, array> */
    public static function routes(): array
    {
        return self::$routes;
    }

    public static function syncRegistry(): void
    {
        if (!Database::tableExists('base_modules')) {
            return;
        }
        $pdo = Database::pdo();
        foreach (self::$modules as $id => $meta) {
            $st = $pdo->prepare('SELECT id FROM base_modules WHERE id = ?');
            $st->execute([$id]);
            $exists = (bool)$st->fetchColumn();
            $enabled = !empty($meta['_enabled']) ? 1 : 0;
            $version = (string)($meta['version'] ?? '0.0.0');
            $name = (string)($meta['name'] ?? $id);
            if ($exists) {
                $upd = $pdo->prepare('UPDATE base_modules SET name=?, version=?, enabled=?, updated_at=? WHERE id=?');
                $upd->execute([$name, $version, $enabled, time(), $id]);
            } else {
                $ins = $pdo->prepare('INSERT INTO base_modules (id, name, version, enabled, installed_at, updated_at) VALUES (?,?,?,?,?,?)');
                $ins->execute([$id, $name, $version, $enabled, time(), time()]);
            }
        }
    }

    /**
     * @return list<array{path:string,title:string,group:string,group_key:string,kind:string,num:int,module:string,permission?:string}>
     */
    public static function menuItems(): array
    {
        $items = self::collectMenuItems();
        if (class_exists('MenuOrder', false)) {
            return MenuOrder::apply($items);
        }
        return $items;
    }

    /**
     * Default module menu order (ignores per-user prefs).
     *
     * @return list<array{path:string,title:string,group:string,group_key:string,kind:string,num:int,module:string,permission?:string}>
     */
    public static function menuItemsUnsorted(): array
    {
        return self::collectMenuItems();
    }

    /**
     * Register default rail position for a menu group key (module.json `menu[].group`).
     * Optional $homePath: click on the section title navigates there (must be a visible menu item).
     * Call from the owning module's bootstrap.php. First registration wins (shared groups: core only).
     * Do not add product module keys to ModuleManager itself.
     */
    public static function registerMenuGroup(string $groupKey, int $weight, ?string $homePath = null): void
    {
        $groupKey = trim($groupKey);
        if ($groupKey === '') {
            return;
        }
        if (!isset(self::$menuGroupWeights[$groupKey])) {
            self::$menuGroupWeights[$groupKey] = $weight;
        }
        $home = $homePath !== null ? trim($homePath) : '';
        if ($home !== '' && !isset(self::$menuGroupHomes[$groupKey])) {
            self::$menuGroupHomes[$groupKey] = $home;
        }
    }

    /**
     * Default rail group order (keys from registerMenuGroup / module bootstraps).
     *
     * @return array<string,int>
     */
    public static function menuGroupWeights(): array
    {
        return self::$menuGroupWeights;
    }

    /**
     * Landing href for a menu group key, if registered.
     */
    public static function menuGroupHome(string $groupKey): ?string
    {
        $groupKey = trim($groupKey);
        if ($groupKey === '' || !isset(self::$menuGroupHomes[$groupKey])) {
            return null;
        }
        return self::$menuGroupHomes[$groupKey];
    }

    /**
     * Menu item kind → rail order within a group: work, then rare CRUDs, then settings.
     *
     * @return array<string,int>
     */
    private static function menuKindWeights(): array
    {
        return ['work' => 0, 'crud' => 1, 'settings' => 2];
    }

    /**
     * Resolve menu[].kind (work|crud|settings). Paths ending in /settings default to settings.
     */
    private static function resolveMenuKind(array $row, string $path): string
    {
        $kind = strtolower(trim((string)($row['kind'] ?? '')));
        $weights = self::menuKindWeights();
        if (isset($weights[$kind])) {
            return $kind;
        }
        $pathOnly = parse_url($path, PHP_URL_PATH);
        $pathOnly = is_string($pathOnly) && $pathOnly !== '' ? $pathOnly : $path;
        if (preg_match('#^/[^/]+/settings/?$#', $pathOnly)) {
            return 'settings';
        }
        return 'work';
    }

    /**
     * @return list<array{path:string,title:string,group:string,group_key:string,kind:string,num:int,module:string,permission?:string}>
     */
    private static function collectMenuItems(): array
    {
        $items = [];
        $weights = self::menuGroupWeights();
        $kindWeights = self::menuKindWeights();
        foreach (self::$modules as $id => $meta) {
            if (empty($meta['_enabled'])) {
                continue;
            }
            $menu = $meta['menu'] ?? [];
            if (!is_array($menu)) {
                continue;
            }
            foreach ($menu as $row) {
                if (!is_array($row) || empty($row['path']) || empty($row['title'])) {
                    continue;
                }
                $path = (string)$row['path'];
                $capability = isset($row['capability']) ? (string)$row['capability'] : '';
                if ($capability === '') {
                    $routePath = parse_url($path, PHP_URL_PATH);
                    $routePath = is_string($routePath) && $routePath !== '' ? $routePath : $path;
                    $route = self::$routes[$routePath] ?? null;
                    if (is_array($route) && isset($route['capability']) && is_string($route['capability'])) {
                        $capability = $route['capability'];
                    }
                }
                if ($capability !== '' && (!Auth::check() || !Auth::capability($capability))) {
                    continue;
                }
                $permission = isset($row['permission']) ? (string)$row['permission'] : self::routePermissionForPath($path);
                if ($permission !== null && (!Auth::check() || !Auth::canSeeInMenu($permission))) {
                    continue;
                }
                $groupKey = (string)($row['group'] ?? $id);
                $item = [
                    'path' => $path,
                    'title' => t((string)$row['title']),
                    'group' => t($groupKey),
                    'group_key' => $groupKey,
                    'kind' => self::resolveMenuKind($row, $path),
                    'num' => (int)($row['num'] ?? 100),
                    'module' => $id,
                ];
                if ($permission !== null) {
                    $item['permission'] = $permission;
                }
                $items[] = $item;
            }
        }

        // Same translated label → one rail section (min weight among keys that share the label).
        $labelWeight = [];
        $labelKey = [];
        foreach ($items as $item) {
            $label = (string)$item['group'];
            $w = $weights[$item['group_key']] ?? 1000;
            if (!isset($labelWeight[$label]) || $w < $labelWeight[$label]) {
                $labelWeight[$label] = $w;
                $labelKey[$label] = (string)$item['group_key'];
            }
        }
        foreach ($items as &$item) {
            $label = (string)$item['group'];
            if (isset($labelKey[$label])) {
                $item['group_key'] = $labelKey[$label];
            }
        }
        unset($item);

        usort($items, static function ($a, $b) use ($labelWeight, $kindWeights) {
            $wa = $labelWeight[$a['group']] ?? 1000;
            $wb = $labelWeight[$b['group']] ?? 1000;
            if ($wa !== $wb) {
                return $wa <=> $wb;
            }
            if ($a['group'] !== $b['group']) {
                return strcmp($a['group'], $b['group']);
            }
            $ka = $kindWeights[$a['kind']] ?? 0;
            $kb = $kindWeights[$b['kind']] ?? 0;
            if ($ka !== $kb) {
                return $ka <=> $kb;
            }
            if ($a['num'] !== $b['num']) {
                return $a['num'] <=> $b['num'];
            }
            return strcmp($a['title'], $b['title']);
        });
        return $items;
    }

    /** Resolve route permission for a menu href (query string ignored). */
    private static function routePermissionForPath(string $href): ?string
    {
        $path = parse_url($href, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $href;
        $route = self::$routes[$path] ?? null;
        if (!is_array($route)) {
            return null;
        }
        $perm = $route['permission'] ?? null;
        return is_string($perm) && $perm !== '' ? $perm : null;
    }

    /** @return array<string, list<array>> */
    public static function menuGrouped(): array
    {
        $grouped = [];
        foreach (self::menuItems() as $item) {
            $grouped[$item['group']][] = $item;
        }
        return $grouped;
    }
}
