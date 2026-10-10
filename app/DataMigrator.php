<?php
declare(strict_types=1);

final class DataMigrator
{
    /**
     * @return list<array{module:string,id:string,status:string,message?:string,stats?:array}>
     */
    public static function runAll(bool $force = true): array
    {
        $report = [];
        foreach (ModuleManager::all() as $modId => $meta) {
            if (empty($meta['_enabled'])) {
                continue;
            }
            foreach (self::specsForModule((string)$modId, $meta) as $spec) {
                $report[] = self::runSpec((string)$modId, $meta, $spec);
            }
        }
        return $report;
    }

    /**
     * Run one migrator by module id + migrator id (safe; does not run the whole catalog).
     *
     * @return array{module:string,id:string,status:string,message?:string,stats?:array}
     */
    public static function runOne(string $moduleId, string $migratorId): array
    {
        $moduleId = trim($moduleId);
        $migratorId = trim($migratorId);
        if ($moduleId === '' || $migratorId === '') {
            return ['module' => $moduleId, 'id' => $migratorId, 'status' => 'error', 'message' => 'module and id required'];
        }
        $meta = ModuleManager::get($moduleId);
        if ($meta === null) {
            return ['module' => $moduleId, 'id' => $migratorId, 'status' => 'error', 'message' => 'Unknown module'];
        }
        foreach (self::specsForModule($moduleId, $meta) as $spec) {
            if ((string)($spec['id'] ?? '') === $migratorId) {
                return self::runSpec($moduleId, $meta, $spec);
            }
        }
        return ['module' => $moduleId, 'id' => $migratorId, 'status' => 'error', 'message' => 'Migrator not declared in module.json'];
    }

    /**
     * @param array<string,mixed> $meta
     * @return list<array<string,mixed>>
     */
    private static function specsForModule(string $modId, array $meta): array
    {
        $migrators = $meta['migrators'] ?? [];
        if (!is_array($migrators)) {
            return [];
        }
        $out = [];
        foreach ($migrators as $spec) {
            if (is_array($spec) && !empty($spec['id'])) {
                $out[] = $spec;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $spec
     * @return array{module:string,id:string,status:string,message?:string,stats?:array}
     */
    private static function runSpec(string $modId, array $meta, array $spec): array
    {
        $id = (string)$spec['id'];
        $dir = (string)($meta['_dir'] ?? '') . '/migrators';
        $file = $dir . '/' . $id . '.php';
        if (!is_file($file)) {
            return ['module' => $modId, 'id' => $id, 'status' => 'error', 'message' => 'File missing'];
        }
        try {
            /** @var callable $fn */
            $fn = require $file;
            if (!is_callable($fn)) {
                throw new RuntimeException('Migrator must return callable');
            }
            $stats = $fn(Database::pdo(), $spec);
            return ['module' => $modId, 'id' => $id, 'status' => 'ok', 'stats' => is_array($stats) ? $stats : []];
        } catch (Throwable $e) {
            return ['module' => $modId, 'id' => $id, 'status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Helper: truncate target and copy rows from source.
     * Copies only columns present in both tables (target-only columns keep defaults/NULL).
     *
     * @return array{source:string,target:string,copied:int}
     */
    public static function replaceTable(PDO $pdo, string $source, string $target): array
    {
        if (!self::tableExists($pdo, $source)) {
            throw new RuntimeException("Source table missing: $source");
        }
        if (!self::tableExists($pdo, $target)) {
            throw new RuntimeException("Target table missing: $target (run schema migrate first)");
        }

        $common = array_values(array_intersect(
            self::columnNames($pdo, $source),
            self::columnNames($pdo, $target)
        ));
        if ($common === []) {
            throw new RuntimeException("No common columns between $source and $target");
        }

        $qSource = '`' . str_replace('`', '``', $source) . '`';
        $qTarget = '`' . str_replace('`', '``', $target) . '`';
        $cols = implode(', ', array_map(
            static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`',
            $common
        ));

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('TRUNCATE TABLE ' . $qTarget);
        $copied = $pdo->exec(
            'INSERT INTO ' . $qTarget . ' (' . $cols . ') SELECT ' . $cols . ' FROM ' . $qSource
        );
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        return ['source' => $source, 'target' => $target, 'copied' => (int)$copied];
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    }

    /** @return list<string> */
    private static function columnNames(PDO $pdo, string $table): array
    {
        $st = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION'
        );
        $st->execute([$table]);
        /** @var list<string> $names */
        $names = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return $names;
    }
}
