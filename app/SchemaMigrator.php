<?php
declare(strict_types=1);

final class SchemaMigrator
{
    /** @var array{needed:bool,pending:int,legacy_users:bool,ledger:bool}|null */
    private static $setupStatusCache = null;

    /**
     * Run schema migrations for all currently enabled modules (core first).
     *
     * @return list<array{module:string,id:string,file:string,status:string,message?:string}>
     */
    public static function runAll(): array
    {
        $report = [];
        foreach (self::enabledModuleOrder() as $id) {
            foreach (self::runModule($id) as $row) {
                $report[] = $row;
            }
        }
        self::clearSetupStatusCache();
        Database::clearSchemaCache();
        return $report;
    }

    /**
     * Enabled modules: core first, then hard-requires (module.json) before dependents.
     * Tie-break: module id ASC. Ensures e.g. comments CREATE runs before contracts indexes.
     *
     * @return list<string>
     */
    public static function enabledModuleOrder(): array
    {
        $enabled = [];
        foreach (ModuleManager::all() as $id => $meta) {
            if (!empty($meta['_enabled'])) {
                $enabled[(string)$id] = $meta;
            }
        }
        if ($enabled === []) {
            return [];
        }

        $depsOf = static function (string $id) use ($enabled): array {
            $meta = $enabled[$id] ?? null;
            if ($meta === null) {
                return [];
            }
            $deps = [];
            foreach ($meta['requires'] ?? [] as $depId => $_) {
                $depId = (string)$depId;
                if ($depId !== '' && $depId !== $id && isset($enabled[$depId])) {
                    $deps[] = $depId;
                }
            }
            return $deps;
        };

        $sorted = [];
        $visiting = [];
        $visit = static function (string $id) use (&$visit, &$sorted, &$visiting, $depsOf, $enabled): void {
            if (isset($sorted[$id])) {
                return;
            }
            if (isset($visiting[$id])) {
                return; // cycle: skip edge
            }
            $visiting[$id] = true;
            $deps = $depsOf($id);
            usort($deps, 'strcmp');
            foreach ($deps as $dep) {
                $visit($dep);
            }
            unset($visiting[$id]);
            $sorted[$id] = true;
        };

        $ids = array_keys($enabled);
        usort($ids, static function (string $a, string $b): int {
            if ($a === 'core') {
                return -1;
            }
            if ($b === 'core') {
                return 1;
            }
            return strcmp($a, $b);
        });
        foreach ($ids as $id) {
            $visit($id);
        }
        return array_keys($sorted);
    }

    /**
     * Run pending schema files for one module (works even if module is not yet enabled —
     * used when connecting a module from the Modules UI).
     *
     * @return list<array{module:string,id:string,file:string,status:string,message?:string}>
     */
    public static function runModule(string $moduleId): array
    {
        $meta = ModuleManager::get($moduleId);
        if ($meta === null) {
            throw new RuntimeException(t('app.module.err.not_found', ['id' => $moduleId]));
        }
        self::ensureLedger();
        $report = [];
        $dir = $meta['_dir'] . '/migrations';
        if (!is_dir($dir)) {
            return $report;
        }
        $files = glob($dir . '/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $migId = basename($file, '.php');
            if (self::applied($moduleId, $migId)) {
                $report[] = ['module' => $moduleId, 'id' => $migId, 'file' => $file, 'status' => 'skip'];
                continue;
            }
            try {
                /** @var callable $fn */
                $fn = require $file;
                if (!is_callable($fn)) {
                    throw new RuntimeException('Migration must return callable');
                }
                $fn(Database::pdo());
                self::markApplied($moduleId, $migId);
                $report[] = ['module' => $moduleId, 'id' => $migId, 'file' => $file, 'status' => 'ok'];
            } catch (Throwable $e) {
                $report[] = [
                    'module' => $moduleId,
                    'id' => $migId,
                    'file' => $file,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
                // Stop this module on first error so order stays consistent
                break;
            }
        }
        self::clearSetupStatusCache();
        Database::clearSchemaCache();
        return $report;
    }

    /**
     * @return list<array{id:string,status:string}>
     */
    public static function listModule(string $moduleId): array
    {
        $meta = ModuleManager::get($moduleId);
        if ($meta === null) {
            return [];
        }
        $dir = $meta['_dir'] . '/migrations';
        if (!is_dir($dir)) {
            return [];
        }
        $ledgerReady = false;
        try {
            $ledgerReady = Database::tableExists('base_schema_migrations');
            if (!$ledgerReady) {
                // Ledger may not exist before first core migrate
                self::ensureLedger();
                $ledgerReady = true;
            }
        } catch (Throwable $e) {
            $ledgerReady = false;
        }

        $out = [];
        $files = glob($dir . '/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $migId = basename($file, '.php');
            $status = 'pending';
            if ($ledgerReady) {
                try {
                    $status = self::applied($moduleId, $migId) ? 'applied' : 'pending';
                } catch (Throwable $e) {
                    $status = 'pending';
                }
            }
            $out[] = ['id' => $migId, 'status' => $status];
        }
        return $out;
    }

    public static function pendingCount(string $moduleId): int
    {
        $n = 0;
        foreach (self::listModule($moduleId) as $row) {
            if ($row['status'] === 'pending') {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Pending schema migrations for enabled modules (for /db UI).
     *
     * @return list<array{module:string,id:string}>
     */
    public static function pendingList(): array
    {
        $out = [];
        foreach (ModuleManager::all() as $id => $meta) {
            if (empty($meta['_enabled'])) {
                continue;
            }
            foreach (self::listModule($id) as $row) {
                if (($row['status'] ?? '') === 'pending') {
                    $out[] = ['module' => $id, 'id' => (string)$row['id']];
                }
            }
        }
        return $out;
    }

    /**
     * Whether the live DB still needs FreeSaaS schema work (pending migrations and/or legacy oper columns).
     * Read-only: does not create the migrations ledger.
     *
     * @return array{needed:bool,pending:int,legacy_users:bool,ledger:bool}
     */
    public static function setupStatus(): array
    {
        if (self::$setupStatusCache !== null) {
            return self::$setupStatusCache;
        }
        $out = ['needed' => false, 'pending' => 0, 'legacy_users' => false, 'ledger' => false];
        try {
            $out['ledger'] = Database::tableExists('base_schema_migrations');
            $pending = 0;
            foreach (ModuleManager::all() as $id => $meta) {
                if (empty($meta['_enabled'])) {
                    continue;
                }
                $dir = (string)($meta['_dir'] ?? '') . '/migrations';
                if (!is_dir($dir)) {
                    continue;
                }
                $files = glob($dir . '/*.php') ?: [];
                if (!$out['ledger']) {
                    $pending += count($files);
                    continue;
                }
                foreach ($files as $file) {
                    $migId = basename($file, '.php');
                    if (!self::applied($id, $migId)) {
                        $pending++;
                    }
                }
            }
            $out['pending'] = $pending;
            $out['legacy_users'] = Database::tableExists('base_users')
                && Database::columnExists('base_users', 'name_enabled')
                && !Database::columnExists('base_users', 'ID_STATUS');
            $out['needed'] = $pending > 0 || $out['legacy_users'] || !$out['ledger'];
        } catch (Throwable $e) {
            error_log('[SchemaMigrator::setupStatus] ' . $e->getMessage());
            $out['needed'] = true;
        }
        return self::$setupStatusCache = $out;
    }

    public static function clearSetupStatusCache(): void
    {
        self::$setupStatusCache = null;
    }

    /** Routes that stay reachable while schema setup is incomplete. */
    public static function isSetupRouteAllowed(string $path): bool
    {
        $path = rtrim($path, '/') ?: '/';
        if (in_array($path, ['/db', '/modules', '/logout', '/locale'], true)) {
            return true;
        }
        return strpos($path, '/agent/') === 0;
    }

    private static function ensureLedger(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS base_schema_migrations (
                module_id VARCHAR(64) NOT NULL,
                migration_id VARCHAR(128) NOT NULL,
                applied_at INT UNSIGNED NOT NULL,
                PRIMARY KEY (module_id, migration_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private static function applied(string $module, string $migId): bool
    {
        $st = Database::pdo()->prepare(
            'SELECT 1 FROM base_schema_migrations WHERE module_id=? AND migration_id=? LIMIT 1'
        );
        $st->execute([$module, $migId]);
        return (bool)$st->fetchColumn();
    }

    private static function markApplied(string $module, string $migId): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_schema_migrations (module_id, migration_id, applied_at) VALUES (?,?,?)'
        );
        $st->execute([$module, $migId, time()]);
    }
}
