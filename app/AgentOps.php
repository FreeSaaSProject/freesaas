<?php
declare(strict_types=1);

/**
 * Shared agent/ops operations used by CLI and HTTP /agent/* API.
 *
 * Module repair ops (bots/gcal/llm secrets, subscribe, …) register via
 * registerModuleOp() from enabled-module bootstrap — same Surface A auth.
 *
 * @return array{ok:bool, http?:int, error?:string, ...}
 */
final class AgentOps
{
    /** @var list<array{method:string,path:string,op:string,handler:callable,description?:string,body?:list<string>,query?:list<string>}> */
    private static array $moduleOps = [];

    /**
     * Register a Surface A op under /agent/* (module bootstrap; same agent_token).
     *
     * @param callable():array<string,mixed> $handler
     * @param array{description?:string,body?:list<string>,query?:list<string>} $meta
     */
    public static function registerModuleOp(
        string $method,
        string $path,
        string $opId,
        callable $handler,
        array $meta = []
    ): void {
        $method = strtoupper(trim($method));
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return;
        }
        if (strpos($path, '/agent/') !== 0) {
            throw new InvalidArgumentException('registerModuleOp path must start with /agent/');
        }
        $opId = trim($opId);
        if ($method === '' || $opId === '') {
            return;
        }
        foreach (self::$moduleOps as $row) {
            if ($row['method'] === $method && $row['path'] === $path) {
                return;
            }
        }
        $entry = [
            'method' => $method,
            'path' => $path,
            'op' => $opId,
            'handler' => $handler,
        ];
        if (isset($meta['description']) && is_string($meta['description']) && $meta['description'] !== '') {
            $entry['description'] = $meta['description'];
        }
        if (isset($meta['body']) && is_array($meta['body'])) {
            $entry['body'] = array_values($meta['body']);
        }
        if (isset($meta['query']) && is_array($meta['query'])) {
            $entry['query'] = array_values($meta['query']);
        }
        self::$moduleOps[] = $entry;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function dispatchModuleOp(string $path, string $method): ?array
    {
        $method = strtoupper(trim($method));
        $path = $path === '/' ? '/' : ('/' . trim($path, '/'));
        foreach (self::$moduleOps as $row) {
            if ($row['method'] === $method && $row['path'] === $path) {
                $out = ($row['handler'])();
                return is_array($out) ? $out : ['ok' => false, 'error' => 'invalid handler result', 'http' => 500];
            }
        }

        return null;
    }

    /**
     * @return list<array{method:string,path:string,op:string,description?:string,body?:list<string>,query?:list<string>}>
     */
    public static function moduleCatalogOps(): array
    {
        $out = [];
        foreach (self::$moduleOps as $row) {
            $item = [
                'method' => $row['method'],
                'path' => $row['path'],
                'op' => $row['op'],
            ];
            if (isset($row['description'])) {
                $item['description'] = $row['description'];
            }
            if (isset($row['body'])) {
                $item['body'] = $row['body'];
            }
            if (isset($row['query'])) {
                $item['query'] = $row['query'];
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * JSON body + form fields for module op handlers (POST).
     *
     * @return array<string,mixed>
     */
    public static function requestInput(): array
    {
        $out = [];
        if (function_exists('agent_api_json_body')) {
            $out = agent_api_json_body();
        } else {
            $raw = function_exists('request_raw_body') ? request_raw_body() : (string)file_get_contents('php://input');
            if (trim($raw) !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $out = $data;
                }
            }
        }
        foreach ($_POST as $k => $v) {
            if (!array_key_exists((string)$k, $out)) {
                $out[(string)$k] = $v;
            }
        }
        foreach ($_GET as $k => $v) {
            if (!array_key_exists((string)$k, $out)) {
                $out[(string)$k] = $v;
            }
        }

        return $out;
    }

    /** Public origin for webhook subscribe URLs (https://host, no trailing slash). */
    public static function publicOrigin(): string
    {
        $host = '';
        if (class_exists('Config', false)) {
            $host = trim((string)Config::get('app', 'admin_host', ''));
            if ($host === '') {
                $host = trim((string)Config::get('app', 'public_host', ''));
            }
            if ($host === '') {
                $host = trim((string)Config::get('app', 'base_url', ''));
            }
        }
        $host = preg_replace('#^https?://#i', '', $host) ?? '';
        $host = rtrim($host, '/');
        $fromConfig = $host !== '';
        if ($host === '') {
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        }
        if ($host === '') {
            return '';
        }
        $https = $fromConfig
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

        return ($https ? 'https' : 'http') . '://' . $host;
    }

    /**
     * @return array{ok:bool,php_version:string,config:string,locale:string,locales_supported:list<string>,modules_discovered:int,modules_enabled:int,modules_blocked:int,database:string,database_error?:string,http?:int}
     */
    public static function systemStatus(): array
    {
        $enabled = 0;
        $blocked = 0;
        foreach (ModuleManager::all() as $meta) {
            if (!empty($meta['_enabled'])) {
                $enabled++;
            }
            if (!empty($meta['_block_reason'])) {
                $blocked++;
            }
        }
        $dbOk = false;
        $dbError = null;
        try {
            Database::pdo()->query('SELECT 1');
            $dbOk = true;
        } catch (Throwable $e) {
            $dbError = $e->getMessage();
        }
        $updateConfigured = false;
        $updateSources = 0;
        try {
            $ust = UpdateEngine::status();
            $updateConfigured = !empty($ust['configured']);
            $updateSources = is_array($ust['sources'] ?? null) ? count($ust['sources']) : 0;
        } catch (Throwable $e) {
            // UpdateEngine optional at boot edge cases
        }
        $payload = [
            'ok' => $dbOk,
            'php_version' => PHP_VERSION,
            'config' => Config::path(),
            'locale' => I18n::locale(),
            'locales_supported' => I18n::supported(),
            'modules_discovered' => count(ModuleManager::all()),
            'modules_enabled' => $enabled,
            'modules_blocked' => $blocked,
            'database' => $dbOk ? 'ok' : 'fail',
            'update_configured' => $updateConfigured,
            'update_sources' => $updateSources,
            'http' => $dbOk ? 200 : 503,
        ];
        if ($dbError !== null) {
            $payload['database_error'] = $dbError;
        }
        return $payload;
    }

    /**
     * @param array{q?:string,category?:string,tag?:string,status?:string,maturity?:string} $filters
     * @return array{ok:bool,modules:list,filters:array,http:int}
     */
    public static function moduleList(array $filters = []): array
    {
        $norm = [
            'q' => trim((string)($filters['q'] ?? '')),
            'category' => trim((string)($filters['category'] ?? '')),
            'tag' => trim((string)($filters['tag'] ?? '')),
            'status' => trim((string)($filters['status'] ?? '')),
            'maturity' => trim((string)($filters['maturity'] ?? '')),
        ];
        $rows = [];
        foreach (ModuleCatalog::list($norm) as $card) {
            $rows[] = [
                'id' => $card['id'],
                'version' => $card['version'],
                'type' => $card['type'],
                'enabled' => $card['enabled'],
                'config_enabled' => $card['config_enabled'],
                'status' => $card['status'],
                'block_reason' => $card['block_reason'],
                'migrations_pending' => $card['migrations_pending'],
                'deps_ok' => $card['deps_ok'],
                'name' => $card['name'],
                'description' => $card['description'],
                'summary' => $card['summary'],
                'catalog' => $card['catalog'],
                'maturity' => $card['maturity'],
                'requires_risk_ack' => $card['requires_risk_ack'],
                'agent_note' => $card['agent_note'],
                'provides' => $card['provides'],
                'requires' => $card['requires'],
                'optionalRequires' => $card['optionalRequires'],
                'can_enable' => $card['can_enable'],
                'can_disable' => $card['can_disable'],
                'can_toggle' => $card['can_toggle'],
                'can_uninstall' => $card['can_uninstall'] ?? false,
                'uninstall_strategy' => $card['uninstall_strategy'] ?? 'drop_owned',
                'can_install' => false,
                'installable' => false,
                'required_by' => $card['required_by'],
            ];
        }
        $available = [];
        foreach (ModuleCatalog::installableList($norm) as $card) {
            $available[] = [
                'id' => $card['id'],
                'version' => $card['version'],
                'type' => $card['type'],
                'enabled' => false,
                'config_enabled' => false,
                'status' => 'available',
                'block_reason' => null,
                'migrations_pending' => 0,
                'deps_ok' => true,
                'name' => $card['name'],
                'description' => $card['description'],
                'summary' => $card['summary'],
                'catalog' => $card['catalog'],
                'maturity' => $card['maturity'],
                'requires_risk_ack' => $card['requires_risk_ack'],
                'agent_note' => $card['agent_note'],
                'provides' => [],
                'requires' => $card['requires'],
                'optionalRequires' => $card['optionalRequires'],
                'can_enable' => false,
                'can_disable' => false,
                'can_toggle' => false,
                'can_uninstall' => false,
                'uninstall_strategy' => 'drop_owned',
                'can_install' => true,
                'installable' => true,
                'required_by' => [],
            ];
        }
        return [
            'ok' => true,
            'modules' => $rows,
            'available' => $available,
            'available_count' => count($available),
            'filters' => $norm,
            'categories' => ModuleCatalog::usedCategories(),
            'tags' => ModuleCatalog::usedTags(),
            'maturities' => ModuleCatalog::MATURITIES,
            'maturity_policy' => ModuleCatalog::maturityPolicy(),
            'http' => 200,
        ];
    }

    /** @return array{ok:bool,module?:array,error?:string,http:int} */
    public static function moduleInspect(string $id): array
    {
        $detail = ModuleCatalog::detail($id);
        if ($detail === null) {
            return ['ok' => false, 'error' => "Unknown module: {$id}", 'http' => 404];
        }
        $meta = ModuleManager::get($id);
        $routes = [];
        foreach (ModuleManager::routes() as $path => $def) {
            if (($def['module'] ?? '') !== $id) {
                continue;
            }
            $routes[] = [
                'path' => (string)$path,
                'methods' => $def['methods'] ?? ['GET'],
                'permission' => $def['permission'] ?? null,
                'capability' => $def['capability'] ?? null,
                'public' => !empty($def['public']),
            ];
        }
        $manifest = is_array($meta) ? $meta : [];
        unset($manifest['_dir']);

        $hooks = [
            'publish' => [],
            'subscribe' => [],
        ];
        foreach (['publish', 'subscribe'] as $side) {
            $list = $manifest['hooks'][$side] ?? [];
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $ev) {
                if (is_string($ev) && $ev !== '') {
                    $hooks[$side][] = $ev;
                }
            }
        }

        $agentOps = [];
        if (class_exists('AgentHttp', false)) {
            $agentOps = AgentHttp::opsForModule($id);
        } else {
            foreach ($manifest['agent']['ops'] ?? [] as $row) {
                if (is_array($row)) {
                    $agentOps[] = $row;
                }
            }
        }

        $bulkManifest = [];
        foreach ($manifest['bulkActions'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bulkManifest[] = [
                'list_id' => (string)($row['list_id'] ?? ''),
                'action' => (string)($row['action'] ?? ''),
                'label' => (string)($row['label'] ?? ''),
                'permission' => isset($row['permission']) ? (string)$row['permission'] : null,
            ];
        }
        $bulkRuntime = class_exists('BulkActions', false) ? BulkActions::catalog($id) : [];

        $capabilityGroups = [];
        if (class_exists('StatusesRepository', false)) {
            foreach (StatusesRepository::fieldGroups() as $g) {
                if (!is_array($g)) {
                    continue;
                }
                $when = (string)($g['when'] ?? '');
                if ($id === 'core' && $when === 'always') {
                    $capabilityGroups[] = $g;
                }
            }
            // Product groups registered at boot (when = module id).
            if (method_exists('StatusesRepository', 'registeredFieldGroups')) {
                foreach (StatusesRepository::registeredFieldGroups() as $g) {
                    if (!is_array($g)) {
                        continue;
                    }
                    if ((string)($g['when'] ?? '') === $id) {
                        $capabilityGroups[] = $g;
                    }
                }
            }
        }

        $softDeps = [];
        $optional = is_array($detail['optionalRequires'] ?? null) ? $detail['optionalRequires'] : [];
        foreach ($optional as $depId => $constraint) {
            $depId = (string)$depId;
            if ($depId === '') {
                continue;
            }
            $depMeta = ModuleManager::get($depId);
            $softDeps[] = [
                'id' => $depId,
                'constraint' => is_string($constraint) ? $constraint : (string)$constraint,
                'present' => $depMeta !== null,
                'enabled' => $depMeta !== null && !empty($depMeta['_enabled']),
            ];
        }

        $tables = [];
        foreach ($manifest['tables'] ?? [] as $t) {
            if (is_string($t) && $t !== '') {
                $tables[] = $t;
            }
        }

        return [
            'ok' => true,
            'module' => [
                'id' => $detail['id'],
                'version' => $detail['version'],
                'type' => $detail['type'],
                'enabled' => $detail['enabled'],
                'config_enabled' => $detail['config_enabled'],
                'status' => $detail['status_full'] ?? ModuleManager::status($id),
                'runtime_status' => $detail['status'],
                'block_reason' => $detail['block_reason'],
                'migrations_pending' => $detail['migrations_pending'],
                'deps_ok' => $detail['deps_ok'],
                'name' => $detail['name'],
                'description' => $detail['description'],
                'summary' => $detail['summary'],
                'catalog' => array_merge($detail['catalog'], [
                    'screenshots' => $detail['screenshots'] ?? [],
                ]),
                'maturity' => $detail['maturity'],
                'requires_risk_ack' => $detail['requires_risk_ack'],
                'agent_note' => $detail['agent_note'],
                'maturity_policy' => ModuleCatalog::maturityPolicy(),
                'provides' => $detail['provides'],
                'requires' => $detail['requires'],
                'optionalRequires' => $detail['optionalRequires'],
                'soft_deps' => $softDeps,
                'can_enable' => $detail['can_enable'],
                'can_disable' => $detail['can_disable'],
                'can_toggle' => $detail['can_toggle'],
                'can_uninstall' => $detail['can_uninstall'] ?? false,
                'uninstall_strategy' => $detail['uninstall_strategy'] ?? ModuleManager::uninstallStrategy($id),
                'required_by' => $detail['required_by'],
                'permissions' => $detail['permissions'] ?? [],
                'tables' => $tables,
                'hooks' => $hooks,
                'agent_ops' => $agentOps,
                'bulk_actions' => [
                    'manifest' => $bulkManifest,
                    'runtime' => $bulkRuntime,
                ],
                'capability_groups' => $capabilityGroups,
                'routes_pages' => $detail['routes_pages'] ?? [],
                'runtime_routes' => $routes,
                'manifest' => $manifest,
            ],
            'http' => 200,
        ];
    }

    /**
     * @return array{ok:bool,module?:string,enabled?:bool,migrations?:list,error?:string,maturity?:string,accept_risk_required?:bool,agent_note?:array,http:int}
     */
    public static function moduleSetEnabled(string $id, bool $enabled, bool $acceptRisk = false): array
    {
        try {
            if ($enabled) {
                $cur = ModuleManager::get($id);
                $alreadyOn = is_array($cur) && !empty($cur['_enabled']);
                $maturity = ModuleCatalog::maturityOf($id);
                if (!$alreadyOn && ModuleCatalog::requiresRiskAck($maturity) && !$acceptRisk) {
                    return [
                        'ok' => false,
                        'error' => 'accept_risk required to enable ' . $maturity . ' module',
                        'module' => $id,
                        'maturity' => $maturity,
                        'accept_risk_required' => true,
                        'agent_note' => ModuleCatalog::agentNote($maturity),
                        'maturity_policy' => ModuleCatalog::maturityPolicy(),
                        'http' => 400,
                    ];
                }
            }
            $report = ModuleManager::setEnabled($id, $enabled);
            return [
                'ok' => true,
                'module' => $id,
                'enabled' => $enabled,
                'migrations' => $report,
                'maturity' => ModuleCatalog::maturityOf($id),
                'http' => 200,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
    }

    /**
     * Download a remote module not yet on disk (UpdateEngine overlay). Stays disabled.
     *
     * @return array<string,mixed>
     */
    public static function moduleInstall(string $id): array
    {
        $r = UpdateEngine::installModule($id);
        if (!empty($r['ok'])) {
            $r['hint'] = 'Module downloaded to modules/' . $id . '; enable via module enable (accept_risk if alpha/beta)';
        }
        return $r;
    }

    /**
     * Dump owned tables then DROP them, clear ledger, disable. Code on disk remains.
     * Requires confirm_uninstall.
     *
     * @return array<string,mixed>
     */
    public static function moduleUninstall(string $id, bool $confirm): array
    {
        try {
            $r = ModuleManager::uninstall($id, $confirm);
            $r['http'] = 200;
            $r['hint'] = 'Tables dropped after dump in var/db_dumps/; module code remains on disk (disabled). Restore dump then module enable to recover.';
            return $r;
        } catch (Throwable $e) {
            $http = 400;
            $msg = $e->getMessage();
            if (strpos($msg, 'confirm_uninstall') !== false || strpos($msg, 'confirm_uninstall=1') !== false) {
                $http = 400;
            }
            return [
                'ok' => false,
                'error' => $msg,
                'module' => $id,
                'confirm_uninstall_required' => !$confirm,
                'uninstall_strategy' => ModuleManager::get($id) !== null
                    ? ModuleManager::uninstallStrategy($id)
                    : null,
                'http' => $http,
            ];
        }
    }

    /** @return array{ok:bool,results?:list,module?:string,errors?:list,warnings?:list,http:int} */
    public static function moduleValidate(?string $id = null): array
    {
        if ($id !== null && $id !== '') {
            $one = ModuleValidator::validate($id);
            $one['http'] = !empty($one['ok']) ? 200 : 422;
            return $one;
        }
        $all = ModuleValidator::validateAll();
        $ok = true;
        foreach ($all as $row) {
            if (empty($row['ok'])) {
                $ok = false;
                break;
            }
        }
        return ['ok' => $ok, 'results' => $all, 'http' => $ok ? 200 : 422];
    }

    /**
     * Bump modules/<id>/module.json → version (sole place UpdateEngine reads).
     * Core also syncs root VERSION (derived copy only).
     *
     * @return array<string,mixed>
     */
    public static function moduleBump(string $id, string $mode = 'patch', ?string $set = null): array
    {
        $id = trim($id);
        if ($id === '') {
            return ['ok' => false, 'error' => 'module id required', 'http' => 400];
        }
        return UpdateEngine::bumpVersion($id, $mode, $set);
    }

    /** @return array{ok:bool,modules:list,setup:array,pending:list,http:int} */
    public static function migrateStatus(?string $module = null): array
    {
        $module = $module !== null ? trim($module) : '';
        $rows = [];
        foreach (ModuleManager::all() as $id => $meta) {
            if ($module !== '' && $id !== $module) {
                continue;
            }
            $list = SchemaMigrator::listModule((string)$id);
            if ($list === [] && $module === '') {
                continue;
            }
            $pending = 0;
            foreach ($list as $m) {
                if ($m['status'] === 'pending') {
                    $pending++;
                }
            }
            $rows[] = [
                'module' => (string)$id,
                'enabled' => !empty($meta['_enabled']),
                'pending' => $pending,
                'migrations' => $list,
            ];
        }
        $setup = SchemaMigrator::setupStatus();
        return [
            'ok' => empty($setup['needed']),
            'setup' => $setup,
            'pending' => $module === '' ? SchemaMigrator::pendingList() : [],
            'modules' => $rows,
            'http' => empty($setup['needed']) ? 200 : 503,
        ];
    }

    /** @return array{ok:bool,report:list,setup?:array,pending?:list,http:int,error?:string} */
    public static function migrateRun(?string $module = null): array
    {
        $module = $module !== null ? trim($module) : '';
        try {
            if ($module !== '') {
                $report = SchemaMigrator::runModule($module);
            } else {
                $report = SchemaMigrator::runAll();
            }
            try {
                ModuleManager::syncRegistry();
            } catch (Throwable $e) {
                // ignore
            }
            $ok = true;
            $applied = 0;
            foreach ($report as $r) {
                if (($r['status'] ?? '') === 'ok') {
                    $applied++;
                }
                if (($r['status'] ?? '') === 'error') {
                    $ok = false;
                }
            }
            $setup = SchemaMigrator::setupStatus();
            return [
                'ok' => $ok && empty($setup['needed']),
                'applied' => $applied,
                'report' => $report,
                'setup' => $setup,
                'pending' => SchemaMigrator::pendingList(),
                'http' => ($ok && empty($setup['needed'])) ? 200 : 500,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'report' => [], 'http' => 400];
        }
    }

    /**
     * Owned-table DB hub status for agents (no expert / no DROP).
     *
     * @return array{ok:bool,owned:list<string>,owned_count:int,dumps:list,setup:array,http:int}
     */
    public static function dbStatus(): array
    {
        try {
            $owned = DbTools::ownedTables();
            $setup = SchemaMigrator::setupStatus();
            return [
                'ok' => true,
                'owned' => $owned,
                'owned_count' => count($owned),
                'dumps' => DbTools::listDumps(),
                'setup' => $setup,
                'pending' => SchemaMigrator::pendingList(),
                'http' => 200,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 500];
        }
    }

    /** @return array{ok:bool,dumps?:list,error?:string,http:int} */
    public static function dbListDumps(): array
    {
        try {
            return ['ok' => true, 'dumps' => DbTools::listDumps(), 'http' => 200];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 500];
        }
    }

    /** @return array{ok:bool,dump?:array,error?:string,http:int} */
    public static function dbDump(?string $label = null): array
    {
        try {
            $dump = DbTools::dump(DbTools::ownedTables(), $label !== null && $label !== '' ? $label : null);
            return ['ok' => true, 'dump' => $dump, 'http' => 200];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
    }

    /** @return array{ok:bool,result?:array,error?:string,http:int} */
    public static function dbRestore(string $file): array
    {
        try {
            $result = DbTools::restore($file, DbTools::declaredTables());
            return ['ok' => true, 'result' => $result, 'http' => 200];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
    }

    /** @return array{ok:bool,file?:string,error?:string,http:int} */
    public static function dbDeleteDump(string $file): array
    {
        try {
            DbTools::deleteDump($file);
            return ['ok' => true, 'file' => basename($file), 'http' => 200];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
    }

    /**
     * @return array{ok:bool,report?:list,error?:string,http:int}
     */
    public static function dbCheck(): array
    {
        return self::dbMaint('check');
    }

    /** @return array{ok:bool,report?:list,error?:string,http:int} */
    public static function dbRepair(): array
    {
        return self::dbMaint('repair');
    }

    /** @return array{ok:bool,report?:list,error?:string,http:int} */
    public static function dbOptimize(): array
    {
        return self::dbMaint('optimize');
    }

    /** @return array{ok:bool,report?:list,error?:string,http:int} */
    private static function dbMaint(string $op): array
    {
        try {
            $tables = DbTools::ownedTables();
            if ($op === 'check') {
                $report = DbTools::check($tables);
            } elseif ($op === 'repair') {
                $report = DbTools::repair($tables);
            } else {
                $report = DbTools::optimize($tables);
            }
            return ['ok' => true, 'op' => $op, 'report' => $report, 'http' => 200];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
    }

    /** @return array{ok:bool,current:string,supported:list<string>,http:int} */
    public static function localeList(): array
    {
        return [
            'ok' => true,
            'current' => I18n::locale(),
            'supported' => I18n::supported(),
            'http' => 200,
        ];
    }

    /** @return array{ok:bool,http:int,...} */
    public static function localeValidate(?string $locale = null): array
    {
        $report = I18n::validateCatalogs($locale);
        $report['http'] = !empty($report['ok']) ? 200 : 422;
        return $report;
    }

    /** @return array{ok:bool,status:string,http:int,...} */
    public static function health(?string $module = null): array
    {
        $module = $module !== null ? trim($module) : '';
        $filter = $module !== '' ? $module : null;
        $report = Health::run($filter);
        $status = (string)($report['status'] ?? 'fail');
        $report['ok'] = $status !== 'fail';
        $report['http'] = $status === 'fail' ? 503 : 200;
        return $report;
    }

    /**
     * Orchestrated diagnose for agents: config → DB → migrate → validate → health → locale.
     *
     * @return array{ok:bool,status:string,error_codes:list<string>,warning_codes:list<string>,sections:array,hints:list<string>,issue_body:string,http:int}
     */
    public static function doctor(): array
    {
        $sections = [];
        $codes = [];
        $warnings = [];
        $hints = [];

        $configPath = Config::path();
        $configExists = is_file($configPath);
        $sections['config'] = [
            'ok' => $configExists,
            'path' => $configPath,
        ];
        if (!$configExists) {
            $codes[] = 'config_missing';
            $hints[] = 'Copy cfg/config.example.ini → cfg/config.ini and set DB + salts.';
        }

        $system = self::systemStatus();
        $sections['system'] = [
            'ok' => !empty($system['ok']),
            'php_version' => $system['php_version'] ?? PHP_VERSION,
            'database' => $system['database'] ?? 'fail',
            'modules_enabled' => $system['modules_enabled'] ?? 0,
            'modules_blocked' => $system['modules_blocked'] ?? 0,
            'locale' => $system['locale'] ?? '',
        ];
        if (($system['database'] ?? '') !== 'ok') {
            $codes[] = 'db_connect_fail';
            $hints[] = 'Fix [connect] in cfg/config.ini; ensure MySQL is up.';
            if (!empty($system['database_error'])) {
                $sections['system']['database_error'] = $system['database_error'];
            }
        }
        if ((int)($system['modules_blocked'] ?? 0) > 0) {
            $codes[] = 'module_blocked';
            $hints[] = 'Inspect blocked modules: php bin/freesaas.php module list --status=blocked --format=json';
        }

        $token = trim((string)Config::get('app', 'agent_token', ''));
        $tokenOk = $token !== '' && strtoupper($token) !== 'CHANGE_ME';
        $sections['agent_token'] = [
            'ok' => true,
            'configured' => $tokenOk,
            'status' => $tokenOk ? 'ok' : 'skipped',
            'error_code' => $tokenOk ? null : 'agent_token_unconfigured',
        ];
        if (!$tokenOk && !self::isVirginInstall()) {
            // Soft: CLI still works; HTTP /agent/* optional.
            $warnings[] = 'agent_token_unconfigured';
            $hints[] = 'Optional: set app.agent_token for HTTP /agent/* (CLI does not need it).';
        }

        $migrate = ['ok' => true, 'pending_total' => 0, 'skipped' => false];
        if (($system['database'] ?? '') === 'ok') {
            try {
                $ms = self::migrateStatus(null);
                $pendingList = is_array($ms['pending'] ?? null) ? $ms['pending'] : [];
                $pending = count($pendingList);
                if ($pending === 0) {
                    foreach (($ms['modules'] ?? []) as $row) {
                        if (is_array($row)) {
                            $pending += (int)($row['pending'] ?? 0);
                        }
                    }
                }
                $setupNeeded = !empty($ms['setup']['needed']);
                $migrate = [
                    'ok' => $pending === 0 && !$setupNeeded,
                    'pending_total' => $pending,
                    'setup_needed' => $setupNeeded,
                ];
                if ($setupNeeded || $pending > 0) {
                    $codes[] = 'schema_pending';
                    $hints[] = 'php bin/freesaas.php migrate run --format=json';
                }
            } catch (Throwable $e) {
                $migrate = ['ok' => false, 'error' => $e->getMessage()];
                $codes[] = 'schema_status_fail';
                $hints[] = 'Check DB permissions / base_schema_migrations.';
            }
        } else {
            $migrate['skipped'] = true;
        }
        $sections['migrate'] = $migrate;

        $validate = ['ok' => true, 'modules_with_errors' => [], 'skipped' => false];
        if (($system['database'] ?? '') === 'ok') {
            try {
                $val = self::moduleValidate(null);
                $errMods = [];
                foreach (($val['results'] ?? []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $mid = (string)($row['module'] ?? '');
                    if ($mid !== '' && (empty($row['ok']) || !empty($row['errors']))) {
                        $errMods[] = $mid;
                    }
                }
                $validate = [
                    'ok' => $errMods === [] && !empty($val['ok']),
                    'modules_with_errors' => $errMods,
                ];
                if ($errMods !== []) {
                    $codes[] = 'module_validate_fail';
                    $hints[] = 'php bin/freesaas.php module validate --format=json';
                }
            } catch (Throwable $e) {
                $validate = ['ok' => false, 'error' => $e->getMessage()];
                $codes[] = 'module_validate_fail';
            }
        } else {
            $validate['skipped'] = true;
        }
        $sections['validate'] = $validate;

        $healthSec = ['ok' => true, 'status' => 'skipped', 'skipped' => true];
        if (($system['database'] ?? '') === 'ok') {
            $h = self::health(null);
            $healthSec = [
                'ok' => !empty($h['ok']),
                'status' => (string)($h['status'] ?? 'unknown'),
                'summary' => $h['summary'] ?? [],
            ];
            if (empty($h['ok'])) {
                $codes[] = 'health_fail';
                $hints[] = 'php bin/freesaas.php health --format=json — see docs/agents/repair.md';
            }
        }
        $sections['health'] = $healthSec;

        $localeSec = ['ok' => true, 'skipped' => false];
        try {
            $loc = self::localeValidate(null);
            $localeSec = [
                'ok' => !empty($loc['ok']),
                'totals' => $loc['totals'] ?? [],
            ];
            if (empty($loc['ok'])) {
                $codes[] = 'locale_fail';
                $hints[] = 'php bin/freesaas.php locale validate --format=json';
            }
        } catch (Throwable $e) {
            $localeSec = ['ok' => false, 'error' => $e->getMessage()];
            $codes[] = 'locale_fail';
        }
        $sections['locale'] = $localeSec;

        $codes = array_values(array_unique($codes));
        $warnings = array_values(array_unique($warnings));
        if (!empty($sections['health']['status']) && $sections['health']['status'] === 'degraded') {
            $warnings[] = 'health_degraded';
            $warnings = array_values(array_unique($warnings));
        }
        $ok = $codes === [];
        $status = 'ok';
        if (!$ok) {
            $status = 'fail';
        } elseif ($warnings !== [] || (!empty($sections['health']['status']) && $sections['health']['status'] === 'degraded')) {
            $status = 'degraded';
        }

        $issueBody = self::doctorIssueBody($status, $codes, $warnings, $sections);

        return [
            'ok' => $ok,
            'status' => $status,
            'error_codes' => $codes,
            'warning_codes' => $warnings,
            'sections' => $sections,
            'hints' => $hints,
            'issue_body' => $issueBody,
            'repair_doc' => 'docs/agents/repair.md',
            'http' => $ok ? 200 : 503,
        ];
    }

    /**
     * @param list<string> $codes
     * @param list<string> $warnings
     * @param array<string,mixed> $sections
     */
    private static function doctorIssueBody(string $status, array $codes, array $warnings, array $sections): string
    {
        $json = json_encode(
            [
                'status' => $status,
                'error_codes' => $codes,
                'warning_codes' => $warnings,
                'sections' => $sections,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        return "## Agent doctor report\n\n"
            . "**Status:** `{$status}`\n"
            . '**Errors:** ' . ($codes === [] ? '_(none)_' : '`' . implode('`, `', $codes) . '`') . "\n"
            . '**Warnings:** ' . ($warnings === [] ? '_(none)_' : '`' . implode('`, `', $warnings) . '`') . "\n\n"
            . "```json\n" . ($json ?: '{}') . "\n```\n\n"
            . "Before a PR: accept CLA — see CONTRIBUTING.md\n";
    }

    /**
     * @return array{ok:bool,id?:string,path?:string,files?:list<string>,error?:string,error_code?:string,http?:int,next?:list<string>}
     */
    public static function moduleCreate(string $id, string $title = ''): array
    {
        return ModuleScaffold::create($id, $title);
    }

    /**
     * @return array{ok:bool,id?:string,migration?:string,path?:string,error?:string,error_code?:string,http?:int,next?:list<string>}
     */
    public static function moduleAddMigration(string $id, string $slug): array
    {
        return ModuleScaffold::addMigration($id, $slug);
    }

    /**
     * Catalog of HTTP ops for agents (shared hosting without SSH).
     *
     * @return array{ok:bool,protocol:string,auth:array,ops:list<array>,cli:string,http:int}
     */
    public static function catalog(): array
    {
        $catalog = [
            'ok' => true,
            'protocol' => 'freesaas-agent/1',
            'auth' => [
                'header' => 'X-FreeSaaS-Agent-Token',
                'bearer' => true,
                'query' => null,
                'query_note' => '?token= is ignored (leaks into logs); use header or Bearer only',
                'config' => 'app.agent_token',
                'setup_key_header' => 'X-FreeSaaS-Setup-Key',
                'setup_key_file' => 'var/setup.key',
                'virgin_open' => false,
                'bootstrap_note' => 'POST /agent/bootstrap requires pre-set app.agent_token (header) or var/setup.key (X-FreeSaaS-Setup-Key); no anonymous virgin door. CLI bootstrap needs no HTTP auth.',
                'rescue_note' => 'POST /agent/rescue with agent_token or setup.key resets/creates superadmin (CLI: rescue)',
                'docs' => 'docs/agents/install.md',
            ],
            'maturity_policy' => ModuleCatalog::maturityPolicy(),
            'ops' => [
                ['method' => 'GET', 'path' => '/agent', 'op' => 'catalog'],
                ['method' => 'POST', 'path' => '/agent/bootstrap', 'op' => 'bootstrap'],
                [
                    'method' => 'POST',
                    'path' => '/agent/rescue',
                    'op' => 'rescue',
                    'description' => 'Reset or create superadmin (agent_token or var/setup.key). Auto-clears setup.key on success.',
                ],
                ['method' => 'GET', 'path' => '/agent/system/status', 'op' => 'system.status'],

                [
                    'method' => 'GET',
                    'path' => '/agent/modules',
                    'op' => 'module.list',
                    'query' => ['q', 'category', 'tag', 'status', 'maturity'],
                    'description' => 'Module catalog: bilocale name/description/summary, maturity, deps, filters. Includes maturity_policy.',
                ],
                [
                    'method' => 'GET',
                    'path' => '/agent/modules/{id}',
                    'op' => 'module.inspect',
                    'description' => 'One module: catalog + status (deps satisfied) + screenshots + agent_note + maturity_policy',
                ],
                [
                    'method' => 'POST',
                    'path' => '/agent/modules/{id}/enable',
                    'op' => 'module.enable',
                    'query' => ['accept_risk'],
                    'body' => ['accept_risk'],
                    'description' => 'Enable + migrate. Alpha/beta require accept_risk=1 (query, form, or JSON). 400 + accept_risk_required if missing.',
                ],
                [
                    'method' => 'POST',
                    'path' => '/agent/modules/{id}/install',
                    'op' => 'module.install',
                    'description' => 'Download module not yet on disk from update repo (modules-catalog.json). Leaves disabled; then module.enable.',
                ],
                [
                    'method' => 'POST',
                    'path' => '/agent/modules/{id}/uninstall',
                    'op' => 'module.uninstall',
                    'query' => ['confirm_uninstall'],
                    'body' => ['confirm_uninstall'],
                    'description' => 'Mandatory dump of exclusively owned module.json tables → DROP those → clear migrations ledger → disable. Tables also claimed by another module are never dropped. leave_tables strategy refused. Code stays on disk. Requires confirm_uninstall=1.',
                ],
                ['method' => 'POST', 'path' => '/agent/modules/{id}/disable', 'op' => 'module.disable'],
                ['method' => 'GET', 'path' => '/agent/modules/{id}/validate', 'op' => 'module.validate'],
                ['method' => 'GET', 'path' => '/agent/validate', 'op' => 'module.validate_all'],
                ['method' => 'GET', 'path' => '/agent/db', 'op' => 'db.status'],
                ['method' => 'GET', 'path' => '/agent/db/migrate', 'op' => 'migrate.status', 'query' => ['module']],
                ['method' => 'POST', 'path' => '/agent/db/migrate/run', 'op' => 'migrate.run', 'query' => ['module']],
                ['method' => 'GET', 'path' => '/agent/db/dumps', 'op' => 'db.dumps'],
                ['method' => 'GET', 'path' => '/agent/db/dump/file', 'op' => 'db.dump_file', 'query' => ['file', 'exp', 'sig']],
                ['method' => 'POST', 'path' => '/agent/db/dump', 'op' => 'db.dump', 'query' => ['label']],
                ['method' => 'POST', 'path' => '/agent/db/restore', 'op' => 'db.restore', 'query' => ['file']],
                ['method' => 'POST', 'path' => '/agent/db/dump/delete', 'op' => 'db.dump_delete', 'query' => ['file']],
                ['method' => 'POST', 'path' => '/agent/db/check', 'op' => 'db.check'],
                ['method' => 'POST', 'path' => '/agent/db/repair', 'op' => 'db.repair'],
                ['method' => 'POST', 'path' => '/agent/db/optimize', 'op' => 'db.optimize'],
                ['method' => 'GET', 'path' => '/agent/locale', 'op' => 'locale.list'],
                ['method' => 'GET', 'path' => '/agent/locale/validate', 'op' => 'locale.validate', 'query' => ['locale']],
                ['method' => 'GET', 'path' => '/agent/health', 'op' => 'health', 'query' => ['module']],
                [
                    'method' => 'GET',
                    'path' => '/agent/doctor',
                    'op' => 'doctor',
                    'description' => 'Orchestrated diagnose: config, DB, migrate pending, validate, health, locale + error_codes + issue_body',
                ],
                ['method' => 'GET', 'path' => '/agent/i18n', 'op' => 'i18n.check', 'query' => ['locale']],
                [
                    'method' => 'GET',
                    'path' => '/agent/update',
                    'op' => 'update.status',
                    'description' => 'Update packages (enabled modules), local/remote versions, available_count, source settings',
                ],
                [
                    'method' => 'GET',
                    'path' => '/agent/update/check',
                    'op' => 'update.check',
                    'query' => ['target'],
                    'description' => 'Contents API: modules/<id>/module.json → version per package. Optional target=core|moduleId',
                ],
                [
                    'method' => 'POST',
                    'path' => '/agent/update/apply',
                    'op' => 'update.apply',
                    'query' => ['target', 'migrate', 'db_backup'],
                    'body' => ['target', 'migrate', 'db_backup'],
                    'description' => 'Snapshot files → overlay → migrate → health (ok). Optional db_backup. target=core|moduleId|all.',
                ],
            ],
            'cli' => 'php bin/freesaas.php … --format=json',
            'http' => 200,
        ];
        $catalog['ops'] = array_merge($catalog['ops'], self::moduleCatalogOps());

        return $catalog;
    }

    /** @return array<string,mixed> */
    public static function updateStatus(): array
    {
        return UpdateEngine::status();
    }

    /** @return array<string,mixed> */
    public static function updateCheck(?string $target = null): array
    {
        return UpdateEngine::check($target);
    }

    /**
     * @return array<string,mixed>
     */
    public static function updateApply(?string $target = null, bool $migrate = true, bool $dbBackup = false): array
    {
        return UpdateEngine::apply($target, $migrate, $dbBackup);
    }

    /**
     * True when the install has no staff users yet (or users table missing).
     * Fail closed: DB/probe errors are NOT treated as virgin (would skip agent_token).
     */
    public static function isVirginInstall(): bool
    {
        try {
            if (!Database::tableExists('base_users')) {
                return true;
            }
            require_once base_path('modules/core/lib/UsersRepository.php');
            return UsersRepository::countUsers() < 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Break-glass: reset or create a superadmin (CLI always; HTTP needs setup.key or agent_token).
     *
     * @param array{login?:string,password?:string,name?:string,mail?:string,clear_setup_key?:bool,migrate?:bool} $opts
     * @return array<string,mixed>
     */
    public static function rescue(array $opts): array
    {
        require_once base_path('modules/core/lib/UsersRepository.php');
        require_once base_path('modules/core/lib/StatusesRepository.php');

        $login = trim((string)($opts['login'] ?? ''));
        $password = (string)($opts['password'] ?? '');
        $name = trim((string)($opts['name'] ?? ''));
        $mail = trim((string)($opts['mail'] ?? ''));
        $wantMigrate = !array_key_exists('migrate', $opts) || !empty($opts['migrate']);
        $clearKey = !empty($opts['clear_setup_key']);

        if ($login === '' || $password === '') {
            return [
                'ok' => false,
                'error' => 'login and password required',
                'http' => 400,
                'hint' => 'php bin/freesaas.php rescue --login=admin --password=…',
            ];
        }
        if (Auth::passwordPolicyError($password, true) !== null) {
            return [
                'ok' => false,
                'error' => 'password must be at least ' . Auth::PASSWORD_MIN_SUPER . ' characters for admin rescue',
                'http' => 400,
            ];
        }

        $steps = [];
        try {
            Database::pdo()->query('SELECT 1');
            $steps[] = ['step' => 'database', 'status' => 'ok'];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => 'database: ' . $e->getMessage(),
                'http' => 503,
                'steps' => $steps,
            ];
        }

        if ($wantMigrate) {
            $migrateReport = SchemaMigrator::runAll();
            try {
                foreach (SchemaMigrator::runModule('core') as $row) {
                    $migrateReport[] = $row;
                }
            } catch (Throwable $e) {
                // ignore duplicate
            }
            Database::clearSchemaCache();
            $migOk = true;
            foreach ($migrateReport as $r) {
                if (($r['status'] ?? '') === 'error') {
                    $migOk = false;
                    break;
                }
            }
            $steps[] = [
                'step' => 'migrate',
                'status' => $migOk ? 'ok' : 'error',
                'report' => $migrateReport,
            ];
            if (!$migOk) {
                return [
                    'ok' => false,
                    'error' => 'schema migrate failed',
                    'http' => 500,
                    'steps' => $steps,
                ];
            }
        }

        try {
            $statusId = self::ensureSuperadminStatus();
            $steps[] = ['step' => 'superadmin_status', 'status' => 'ok', 'status_id' => $statusId];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => 'status: ' . $e->getMessage(),
                'http' => 500,
                'steps' => $steps,
                'hint' => 'Run schema migrate first: php bin/freesaas.php migrate run',
            ];
        }

        $perms = [];
        foreach (UsersRepository::availablePermissionKeys() as $g) {
            foreach ($g['keys'] as $key) {
                $pid = is_array($key) ? (string)$key['id'] : (string)$key;
                if ($pid !== '') {
                    $perms[$pid] = 1;
                }
            }
        }
        $blob = UsersRepository::encodeStatusBlob(null, $perms);

        try {
            $existing = UsersRepository::findByLogin($login);
            if ($existing !== null) {
                $userId = (int)$existing['id'];
                UsersRepository::update($userId, [
                    'password_plain' => $password,
                    'enabled' => '1',
                    'ID_STATUS' => $statusId,
                    'status_blob' => $blob,
                    'name' => $name !== '' ? $name : (string)($existing['name'] ?? $login),
                    'mail' => $mail !== '' ? $mail : (string)($existing['mail'] ?? ''),
                ]);
                $steps[] = [
                    'step' => 'admin_user',
                    'status' => 'ok',
                    'action' => 'reset',
                    'user_id' => $userId,
                    'login' => $login,
                ];
            } else {
                $userId = UsersRepository::create([
                    'login' => $login,
                    'password_plain' => $password,
                    'name' => $name !== '' ? $name : $login,
                    'mail' => $mail,
                    'enabled' => '1',
                    'ID_STATUS' => $statusId,
                    'status_blob' => $blob,
                ]);
                $steps[] = [
                    'step' => 'admin_user',
                    'status' => 'ok',
                    'action' => 'created',
                    'user_id' => $userId,
                    'login' => $login,
                ];
            }
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => 'admin user: ' . $e->getMessage(),
                'http' => 500,
                'steps' => $steps,
            ];
        }

        // Always drop break-glass key after successful rescue (flag kept for API compat).
        if (SetupKey::exists() || $clearKey) {
            SetupKey::clear();
            $steps[] = ['step' => 'setup_key', 'status' => 'cleared'];
        }

        return [
            'ok' => true,
            'login' => $login,
            'user_id' => $userId,
            'login_url' => '/login',
            'steps' => $steps,
            'message' => 'Rescue complete — sign in at /login',
            'http' => 200,
        ];
    }

    /**
     * Greenfield / shared-host bootstrap: migrate schema, ensure superadmin role + first user,
     * optionally enable modules and persist agent_token.
     *
     * @param array{
     *   login?:string,
     *   password?:string,
     *   name?:string,
     *   mail?:string,
     *   locale?:string,
     *   agent_token?:string,
     *   migrate?:bool,
     *   modules?:list<string>,
     *   grant_all_permissions?:bool
     * } $opts
     * @return array<string,mixed>
     */
    public static function bootstrap(array $opts): array
    {
        require_once base_path('modules/core/lib/UsersRepository.php');
        require_once base_path('modules/core/lib/StatusesRepository.php');

        $virgin = self::isVirginInstall();
        $login = trim((string)($opts['login'] ?? ''));
        $password = (string)($opts['password'] ?? '');
        $name = trim((string)($opts['name'] ?? ''));
        $mail = trim((string)($opts['mail'] ?? ''));
        $locale = I18n::normalize(isset($opts['locale']) ? (string)$opts['locale'] : null);
        $wantMigrate = !array_key_exists('migrate', $opts) || !empty($opts['migrate']);
        $grantAll = !array_key_exists('grant_all_permissions', $opts) || !empty($opts['grant_all_permissions']);
        $tokenIn = trim((string)($opts['agent_token'] ?? ''));
        $modulesEnable = [];
        if (isset($opts['modules']) && is_array($opts['modules'])) {
            foreach ($opts['modules'] as $m) {
                $m = trim((string)$m);
                if ($m !== '' && $m !== 'core') {
                    $modulesEnable[] = $m;
                }
            }
        }

        $steps = [];

        // Persist agent token when provided (required for virgin HTTP bootstrap if unset)
        if ($tokenIn !== '' && $tokenIn !== 'CHANGE_ME') {
            try {
                Config::set('app', 'agent_token', $tokenIn);
                $steps[] = ['step' => 'agent_token', 'status' => 'ok', 'message' => 'app.agent_token saved'];
            } catch (Throwable $e) {
                return [
                    'ok' => false,
                    'error' => 'failed to save agent_token: ' . $e->getMessage(),
                    'http' => 500,
                    'steps' => $steps,
                ];
            }
        }

        if (!AgentAuth::isConfigured()) {
            if ($virgin) {
                return [
                    'ok' => false,
                    'error' => 'app.agent_token is required (pass agent_token in body or set in cfg/config.ini)',
                    'http' => 503,
                    'steps' => $steps,
                    'hint' => 'FTP-write cfg/config.ini [app] agent_token=… then retry, or include agent_token in bootstrap JSON',
                ];
            }
            $steps[] = [
                'step' => 'agent_token',
                'status' => 'skip',
                'message' => 'not set — HTTP /agent/* disabled until configured',
            ];
        }

        // DB ping
        try {
            Database::pdo()->query('SELECT 1');
            $steps[] = ['step' => 'database', 'status' => 'ok'];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => 'database: ' . $e->getMessage(),
                'http' => 503,
                'steps' => $steps,
            ];
        }

        $migrateReport = [];
        if ($wantMigrate) {
            // Virgin + modules[]: pin enabled set (Config defaults unknown keys to ON).
            // Non-virgin: only enable listed modules; never disable others on re-bootstrap.
            if ($virgin && isset($opts['modules']) && is_array($opts['modules'])) {
                $want = [];
                foreach ($modulesEnable as $mid) {
                    $want[$mid] = true;
                }
                // Include hard deps of requested modules so boot/migrate can succeed.
                $changed = true;
                while ($changed) {
                    $changed = false;
                    foreach (array_keys($want) as $mid) {
                        $meta = ModuleManager::get($mid);
                        if ($meta === null) {
                            continue;
                        }
                        foreach ($meta['requires'] ?? [] as $depId => $_) {
                            $depId = (string)$depId;
                            if ($depId === '' || $depId === 'core' || isset($want[$depId])) {
                                continue;
                            }
                            if (ModuleManager::get($depId) !== null) {
                                $want[$depId] = true;
                                $changed = true;
                            }
                        }
                    }
                }
                foreach (ModuleManager::all() as $mid => $_) {
                    $mid = (string)$mid;
                    if ($mid === 'core') {
                        continue;
                    }
                    $on = isset($want[$mid]);
                    if (Config::moduleEnabled($mid) === $on) {
                        continue;
                    }
                    try {
                        Config::setModuleEnabled($mid, $on);
                        $steps[] = [
                            'step' => ($on ? 'enable.' : 'disable.') . $mid,
                            'status' => 'ok',
                            'message' => 'pinned by virgin bootstrap modules[]',
                        ];
                    } catch (Throwable $e) {
                        $steps[] = [
                            'step' => ($on ? 'enable.' : 'disable.') . $mid,
                            'status' => 'error',
                            'message' => $e->getMessage(),
                        ];
                    }
                }
                ModuleManager::rediscover();
            } else {
                foreach ($modulesEnable as $mid) {
                    if (ModuleManager::get($mid) === null) {
                        $steps[] = ['step' => 'enable.' . $mid, 'status' => 'skip', 'message' => 'unknown module'];
                        continue;
                    }
                    try {
                        if (!Config::moduleEnabled($mid)) {
                            ModuleManager::setEnabled($mid, true);
                            $steps[] = ['step' => 'enable.' . $mid, 'status' => 'ok'];
                        }
                    } catch (Throwable $e) {
                        $steps[] = ['step' => 'enable.' . $mid, 'status' => 'error', 'message' => $e->getMessage()];
                    }
                }
            }
            $migrateReport = SchemaMigrator::runAll();
            // Core ACL migration must apply even if somehow skipped — also run core explicitly
            try {
                foreach (SchemaMigrator::runModule('core') as $row) {
                    $migrateReport[] = $row;
                }
            } catch (Throwable $e) {
                // ignore duplicate
            }
            Database::clearSchemaCache();
            try {
                ModuleManager::syncRegistry();
            } catch (Throwable $e) {
                // ignore
            }
            $migOk = true;
            foreach ($migrateReport as $r) {
                if (($r['status'] ?? '') === 'error') {
                    $migOk = false;
                    break;
                }
            }
            $steps[] = [
                'step' => 'migrate',
                'status' => $migOk ? 'ok' : 'error',
                'report' => $migrateReport,
            ];
            if (!$migOk) {
                return [
                    'ok' => false,
                    'error' => 'schema migrate failed',
                    'http' => 500,
                    'steps' => $steps,
                    'migrations' => $migrateReport,
                ];
            }
        }

        // Ensure superadmin role
        $statusId = 0;
        try {
            $statusId = self::ensureSuperadminStatus();
            $steps[] = ['step' => 'superadmin_status', 'status' => 'ok', 'status_id' => $statusId];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => 'status: ' . $e->getMessage(),
                'http' => 500,
                'steps' => $steps,
            ];
        }

        $userId = null;
        $createdUser = false;
        // Re-check after migrate (table may have been created empty, or already populated)
        $stillVirgin = self::isVirginInstall();
        if (!$stillVirgin) {
            $steps[] = [
                'step' => 'admin_user',
                'status' => 'skip',
                'message' => 'users already exist — admin not created',
                'user_count' => UsersRepository::countUsers(),
            ];
        } else {
            if ($login === '' || $password === '') {
                return [
                    'ok' => false,
                    'error' => 'login and password required for first admin',
                    'http' => 400,
                    'steps' => $steps,
                ];
            }
            if (Auth::passwordPolicyError($password, true) !== null) {
                return [
                    'ok' => false,
                    'error' => 'password must be at least ' . Auth::PASSWORD_MIN_SUPER . ' characters for first admin',
                    'http' => 400,
                    'steps' => $steps,
                ];
            }
            $perms = [];
            if ($grantAll) {
                foreach (UsersRepository::availablePermissionKeys() as $g) {
                    foreach ($g['keys'] as $key) {
                        $pid = is_array($key) ? (string)$key['id'] : (string)$key;
                        if ($pid !== '') {
                            $perms[$pid] = 1;
                        }
                    }
                }
            }
            try {
                $userId = UsersRepository::create([
                    'login' => $login,
                    'password_plain' => $password,
                    'name' => $name !== '' ? $name : $login,
                    'mail' => $mail,
                    'enabled' => '1',
                    'ID_STATUS' => $statusId,
                    'status_blob' => UsersRepository::encodeStatusBlob(null, $perms),
                    'locale' => $locale ?? I18n::locale(),
                ]);
                $createdUser = true;
                $steps[] = [
                    'step' => 'admin_user',
                    'status' => 'ok',
                    'user_id' => $userId,
                    'login' => $login,
                    'permissions' => count($perms),
                ];
            } catch (Throwable $e) {
                return [
                    'ok' => false,
                    'error' => 'admin user: ' . $e->getMessage(),
                    'http' => 500,
                    'steps' => $steps,
                ];
            }
        }

        // Seed base_system row if empty
        try {
            if (Database::tableExists('base_system')) {
                $n = (int)Database::pdo()->query('SELECT COUNT(*) FROM base_system')->fetchColumn();
                if ($n < 1) {
                    $brand = (string)Config::get('app', 'name', 'FreeSaaS');
                    Database::pdo()->prepare(
                        'INSERT INTO base_system (name, company, title) VALUES (?,?,?)'
                    )->execute([$brand, $brand, $brand]);
                    $steps[] = ['step' => 'system_row', 'status' => 'ok'];
                }
            }
        } catch (Throwable $e) {
            $steps[] = ['step' => 'system_row', 'status' => 'skip', 'message' => $e->getMessage()];
        }

        if (SetupKey::exists()) {
            SetupKey::clear();
            $steps[] = ['step' => 'setup_key', 'status' => 'cleared'];
        }

        $health = Health::run(null);

        return [
            'ok' => true,
            'virgin' => $stillVirgin,
            'created_admin' => $createdUser,
            'user_id' => $userId,
            'login' => $createdUser ? $login : null,
            'status_id' => $statusId,
            'agent_api' => '/agent',
            'login_url' => '/login',
            'health' => [
                'status' => $health['status'] ?? null,
                'summary' => $health['summary'] ?? null,
            ],
            'steps' => $steps,
            'migrations' => $migrateReport,
            'http' => 200,
            'message' => $createdUser
                ? 'Bootstrap complete — sign in at /login with the provided credentials'
                : 'Bootstrap migrate/health complete — admin already present',
        ];
    }

    private static function ensureSuperadminStatus(): int
    {
        if (!Database::tableExists('base_admin_statuses')) {
            throw new RuntimeException('base_admin_statuses missing after migrate');
        }
        if (Database::columnExists('base_admin_statuses', 'SUPERADMIN_ACCESS')) {
            $st = Database::pdo()->query(
                'SELECT ID FROM base_admin_statuses WHERE SUPERADMIN_ACCESS > 0 ORDER BY ID ASC LIMIT 1'
            );
            $id = (int)$st->fetchColumn();
            if ($id > 0) {
                // Greenfield Superadmin needs shift-assign flags once columns exist (core/013).
                $patch = [];
                if (Database::columnExists('base_admin_statuses', 'EVT_ASSIGN')) {
                    $patch['EVT_ASSIGN'] = 1;
                }
                if (Database::columnExists('base_admin_statuses', 'CAN_BE_ASSIGNED')) {
                    $patch['CAN_BE_ASSIGNED'] = 1;
                }
                if ($patch !== []) {
                    StatusesRepository::update($id, $patch);
                }
                return $id;
            }
        }
        $data = [
            'NAME' => 'Superadmin',
            'ENABLED' => 1,
            'SUPERADMIN_ACCESS' => 1,
            'CONTROL_ACCESS' => 1,
            'SHOW_ADMINISTRATOR_DATA' => 1,
            'SHOW_NEWS' => 1,
            'CAN_SEE_SUM_OTHERS' => 1,
            'EVT_ASSIGN' => 1,
            'CAN_BE_ASSIGNED' => 1,
        ];
        return StatusesRepository::create($data);
    }
}
