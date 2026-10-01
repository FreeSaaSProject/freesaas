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
            if (empty($meta['_enabled'])) continue;
            $migrators = $meta['migrators'] ?? [];
            if (!is_array($migrators)) continue;
            $dir = $meta['_dir'] . '/migrators';
            foreach ($migrators as $spec) {
                if (!is_array($spec) || empty($spec['id'])) continue;
                $id = (string)$spec['id'];
                $file = $dir . '/' . $id . '.php';
                if (!is_file($file)) {
                    $report[] = ['module' => $modId, 'id' => $id, 'status' => 'error', 'message' => 'File missing'];
                    continue;
                }
                try {
                    /** @var callable $fn */
                    $fn = require $file;
                    if (!is_callable($fn)) {
                        throw new RuntimeException('Migrator must return callable');
                    }
                    $stats = $fn(Database::pdo(), $spec);
                    $report[] = ['module' => $modId, 'id' => $id, 'status' => 'ok', 'stats' => is_array($stats) ? $stats : []];
                } catch (Throwable $e) {
                    $report[] = ['module' => $modId, 'id' => $id, 'status' => 'error', 'message' => $e->getMessage()];
                }
            }
        }
        return $report;
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
