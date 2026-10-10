<?php
declare(strict_types=1);

/**
 * Shared agent/ops operations used by CLI and HTTP /agent/* API.
 *
 * @return array{ok:bool, http?:int, error?:string, ...}
 */
final class AgentOps
{
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
                'can_enable' => $detail['can_enable'],
                'can_disable' => $detail['can_disable'],
                'can_toggle' => $detail['can_toggle'],
                'required_by' => $detail['required_by'],
                'permissions' => $detail['permissions'] ?? [],
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
     * Catalog of HTTP ops for agents (shared hosting without SSH).
     *
     * @return array{ok:bool,protocol:string,auth:array,ops:list<array>,cli:string,http:int}
     */
    public static function catalog(): array
    {
        return [
            'ok' => true,
            'protocol' => 'freesaas-agent/1',
            'auth' => [
                'header' => 'X-FreeSaaS-Agent-Token',
                'query' => 'token',
                'config' => 'app.agent_token',
                'bootstrap_note' => 'POST /agent/bootstrap on virgin install may include agent_token in JSON body without prior header',
            ],
            'maturity_policy' => ModuleCatalog::maturityPolicy(),
            'ops' => [
                ['method' => 'GET', 'path' => '/agent', 'op' => 'catalog'],
                ['method' => 'POST', 'path' => '/agent/bootstrap', 'op' => 'bootstrap'],
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
                ['method' => 'POST', 'path' => '/agent/modules/{id}/disable', 'op' => 'module.disable'],
                ['method' => 'GET', 'path' => '/agent/modules/{id}/validate', 'op' => 'module.validate'],
                ['method' => 'GET', 'path' => '/agent/validate', 'op' => 'module.validate_all'],
                ['method' => 'GET', 'path' => '/agent/db', 'op' => 'db.status'],
                ['method' => 'GET', 'path' => '/agent/db/migrate', 'op' => 'migrate.status', 'query' => ['module']],
                ['method' => 'POST', 'path' => '/agent/db/migrate/run', 'op' => 'migrate.run', 'query' => ['module']],
                ['method' => 'GET', 'path' => '/agent/db/dumps', 'op' => 'db.dumps'],
                ['method' => 'GET', 'path' => '/agent/db/dump/file', 'op' => 'db.dump_file', 'query' => ['file', 'token']],
                ['method' => 'POST', 'path' => '/agent/db/dump', 'op' => 'db.dump', 'query' => ['label']],
                ['method' => 'POST', 'path' => '/agent/db/restore', 'op' => 'db.restore', 'query' => ['file']],
                ['method' => 'POST', 'path' => '/agent/db/dump/delete', 'op' => 'db.dump_delete', 'query' => ['file']],
                ['method' => 'POST', 'path' => '/agent/db/check', 'op' => 'db.check'],
                ['method' => 'POST', 'path' => '/agent/db/repair', 'op' => 'db.repair'],
                ['method' => 'POST', 'path' => '/agent/db/optimize', 'op' => 'db.optimize'],
                ['method' => 'GET', 'path' => '/agent/locale', 'op' => 'locale.list'],
                ['method' => 'GET', 'path' => '/agent/locale/validate', 'op' => 'locale.validate', 'query' => ['locale']],
                ['method' => 'GET', 'path' => '/agent/health', 'op' => 'health', 'query' => ['module']],
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
                    'query' => ['target', 'migrate'],
                    'body' => ['target', 'migrate'],
                    'description' => 'Download one monorepo zip/zipball + overlay + migrate. target=core|moduleId|all.',
                ],
            ],
            'cli' => 'php bin/freesaas.php … --format=json',
            'http' => 200,
        ];
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
    public static function updateApply(?string $target = null, bool $migrate = true): array
    {
        return UpdateEngine::apply($target, $migrate);
    }

    /**
     * True when the install has no staff users yet (or users table missing).
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
            return true;
        }
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
            if (strlen($password) < 8) {
                return [
                    'ok' => false,
                    'error' => 'password must be at least 8 characters',
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
