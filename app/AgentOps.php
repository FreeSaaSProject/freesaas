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
            'http' => $dbOk ? 200 : 503,
        ];
        if ($dbError !== null) {
            $payload['database_error'] = $dbError;
        }
        return $payload;
    }

    /** @return array{ok:bool,modules:list<array>} */
    public static function moduleList(): array
    {
        $rows = [];
        foreach (ModuleManager::all() as $id => $meta) {
            $st = ModuleManager::status((string)$id);
            $rows[] = [
                'id' => (string)$id,
                'version' => (string)($meta['version'] ?? ''),
                'type' => (string)($meta['type'] ?? ''),
                'enabled' => !empty($meta['_enabled']),
                'config_enabled' => $st['config_enabled'],
                'block_reason' => $st['block_reason'],
                'migrations_pending' => $st['migrations_pending'],
                'requires' => $meta['requires'] ?? [],
                'optionalRequires' => $meta['optionalRequires'] ?? [],
            ];
        }
        return ['ok' => true, 'modules' => $rows, 'http' => 200];
    }

    /** @return array{ok:bool,module?:array,error?:string,http:int} */
    public static function moduleInspect(string $id): array
    {
        $meta = ModuleManager::get($id);
        if ($meta === null) {
            return ['ok' => false, 'error' => "Unknown module: {$id}", 'http' => 404];
        }
        $st = ModuleManager::status($id);
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
        $out = $meta;
        unset($out['_dir']);
        $out['status'] = $st;
        $out['runtime_routes'] = $routes;
        return ['ok' => true, 'module' => $out, 'http' => 200];
    }

    /** @return array{ok:bool,module?:string,enabled?:bool,migrations?:list,error?:string,http:int} */
    public static function moduleSetEnabled(string $id, bool $enabled): array
    {
        try {
            $report = ModuleManager::setEnabled($id, $enabled);
            return [
                'ok' => true,
                'module' => $id,
                'enabled' => $enabled,
                'migrations' => $report,
                'http' => 200,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
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
            'ops' => [
                ['method' => 'GET', 'path' => '/agent', 'op' => 'catalog'],
                ['method' => 'POST', 'path' => '/agent/bootstrap', 'op' => 'bootstrap'],
                ['method' => 'GET', 'path' => '/agent/system/status', 'op' => 'system.status'],
                ['method' => 'GET', 'path' => '/agent/modules', 'op' => 'module.list'],
                ['method' => 'GET', 'path' => '/agent/modules/{id}', 'op' => 'module.inspect'],
                ['method' => 'POST', 'path' => '/agent/modules/{id}/enable', 'op' => 'module.enable'],
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
            ],
            'cli' => 'php bin/freesaas.php … --format=json',
            'http' => 200,
        ];
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
            // Enable requested modules first so runAll includes them
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
        ];
        return StatusesRepository::create($data);
    }
}
