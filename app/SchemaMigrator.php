<?php
declare(strict_types=1);

final class SchemaMigrator
{
    /**
     * Run schema migrations for all currently enabled modules.
     *
     * @return list<array{module:string,id:string,file:string,status:string,message?:string}>
     */
    public static function runAll(): array
    {
        $report = [];
        foreach (ModuleManager::all() as $id => $meta) {
            if (empty($meta['_enabled'])) {
                continue;
            }
            foreach (self::runModule($id) as $row) {
                $report[] = $row;
            }
        }
        return $report;
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
