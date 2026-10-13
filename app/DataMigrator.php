<?php
declare(strict_types=1);

final class DataMigrator
{
    /** @var callable|null fn(string $phase, array $row): void  phase=start|done */
    private static $progress = null;

    /**
     * Optional progress hook (CLI): called with phase "start"|"done" and the report row.
     *
     * @param callable|null $fn
     */
    public static function setProgress(?callable $fn): void
    {
        self::$progress = $fn;
    }

    /**
     * Catalog of data migrators for enabled modules (preview before run).
     *
     * @return list<array{
     *   module:string,id:string,mode:string,
     *   source:list<string>,target:list<string>,
     *   description:string,description_key:string
     * }>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (ModuleManager::all() as $modId => $meta) {
            if (empty($meta['_enabled'])) {
                continue;
            }
            foreach (self::specsForModule((string)$modId, $meta) as $spec) {
                $out[] = self::describeSpec((string)$modId, $spec);
            }
        }
        return $out;
    }

    /**
     * @return list<array{module:string,id:string,status:string,message?:string,stats?:array,mode?:string,description?:string}>
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
     * @return array{module:string,id:string,status:string,message?:string,stats?:array,mode?:string,description?:string}
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
     * Human-readable one-line summary from a report row (CLI / UI).
     *
     * @param array<string,mixed> $row
     */
    public static function formatSummary(array $row): string
    {
        $bits = [];
        $stats = is_array($row['stats'] ?? null) ? $row['stats'] : [];
        foreach ($stats as $k => $v) {
            if ($k === 'ok' || $k === 'message' || $k === 'permissions_message') {
                continue;
            }
            if (is_bool($v)) {
                $bits[] = $k . '=' . ($v ? '1' : '0');
            } elseif (is_scalar($v)) {
                $bits[] = $k . '=' . $v;
            }
        }
        $msg = '';
        if (!empty($row['message']) && is_string($row['message'])) {
            $msg = $row['message'];
        } elseif (!empty($stats['message']) && is_string($stats['message'])) {
            $msg = $stats['message'];
        } elseif (!empty($stats['permissions_message']) && is_string($stats['permissions_message'])) {
            $msg = $stats['permissions_message'];
        }
        $head = $bits !== [] ? implode(', ', $bits) : '';
        // Prefer the human message when present; append compact stats only if they add numbers.
        if ($msg !== '' && $head !== '') {
            // Avoid "message — message" when message already embeds the stats.
            if (strpos($msg, (string)($stats['copied'] ?? '___')) !== false
                || strpos($msg, 'inserted') !== false
                || strpos($msg, 'updated') !== false
                || strpos($msg, 'scanned') !== false
            ) {
                return $msg;
            }
            return $msg . ' (' . $head . ')';
        }
        return $msg !== '' ? $msg : $head;
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
     * @param array<string,mixed> $spec
     * @return array{
     *   module:string,id:string,mode:string,
     *   source:list<string>,target:list<string>,
     *   description:string,description_key:string
     * }
     */
    private static function describeSpec(string $modId, array $spec): array
    {
        $id = (string)$spec['id'];
        $mode = (string)($spec['mode'] ?? 'replace');
        $source = self::stringList($spec['source'] ?? []);
        $target = self::stringList($spec['target'] ?? []);
        $descKey = (string)($spec['description'] ?? '');
        if ($descKey === '') {
            $descKey = $modId . '.migrator.' . $id;
        }
        $description = '';
        if ($descKey !== '' && function_exists('t')) {
            // Prefer translated key when catalog has it; else fall back to source→target.
            $translated = t($descKey);
            if ($translated !== $descKey) {
                $description = $translated;
            }
        }
        if ($description === '') {
            $src = $source !== [] ? implode(', ', $source) : '—';
            $dst = $target !== [] ? implode(', ', $target) : '—';
            $description = $mode . ': ' . $src . ' → ' . $dst;
        }
        return [
            'module' => $modId,
            'id' => $id,
            'mode' => $mode,
            'source' => $source,
            'target' => $target,
            'description' => $description,
            'description_key' => $descKey,
        ];
    }

    /**
     * @param mixed $v
     * @return list<string>
     */
    private static function stringList($v): array
    {
        if (!is_array($v)) {
            return is_string($v) && $v !== '' ? [$v] : [];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $spec
     * @return array{module:string,id:string,status:string,message?:string,stats?:array,mode?:string,description?:string}
     */
    private static function runSpec(string $modId, array $meta, array $spec): array
    {
        $id = (string)$spec['id'];
        $info = self::describeSpec($modId, $spec);
        $startRow = [
            'module' => $modId,
            'id' => $id,
            'status' => 'running',
            'mode' => $info['mode'],
            'description' => $info['description'],
        ];
        if (self::$progress !== null) {
            (self::$progress)('start', $startRow);
        }

        $dir = (string)($meta['_dir'] ?? '') . '/migrators';
        $file = $dir . '/' . $id . '.php';
        if (!is_file($file)) {
            $row = [
                'module' => $modId,
                'id' => $id,
                'status' => 'error',
                'message' => 'File missing',
                'mode' => $info['mode'],
                'description' => $info['description'],
            ];
            if (self::$progress !== null) {
                (self::$progress)('done', $row);
            }
            return $row;
        }
        try {
            /** @var callable $fn */
            $fn = require $file;
            if (!is_callable($fn)) {
                throw new RuntimeException('Migrator must return callable');
            }
            $stats = $fn(Database::pdo(), $spec);
            if (!is_array($stats)) {
                $stats = [];
            }
            $status = 'ok';
            $message = '';
            if (array_key_exists('ok', $stats) && $stats['ok'] === false) {
                $status = 'error';
            }
            if (!empty($stats['message']) && is_string($stats['message'])) {
                $message = $stats['message'];
            } elseif (!empty($stats['permissions_message']) && is_string($stats['permissions_message'])) {
                $message = $stats['permissions_message'];
            }
            $row = [
                'module' => $modId,
                'id' => $id,
                'status' => $status,
                'stats' => $stats,
                'mode' => $info['mode'],
                'description' => $info['description'],
            ];
            if ($message !== '') {
                $row['message'] = $message;
            }
            if (self::$progress !== null) {
                (self::$progress)('done', $row);
            }
            return $row;
        } catch (Throwable $e) {
            $row = [
                'module' => $modId,
                'id' => $id,
                'status' => 'error',
                'message' => $e->getMessage(),
                'mode' => $info['mode'],
                'description' => $info['description'],
            ];
            if (self::$progress !== null) {
                (self::$progress)('done', $row);
            }
            return $row;
        }
    }

    /**
     * Helper: truncate target and copy rows from source.
     * Copies only columns present in both tables (target-only columns keep defaults/NULL).
     *
     * @return array{source:string,target:string,copied:int,message:string,ok:bool}
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

        $n = (int)$copied;
        return [
            'ok' => true,
            'source' => $source,
            'target' => $target,
            'copied' => $n,
            'message' => "copied {$n} row(s) {$source} → {$target}",
        ];
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
