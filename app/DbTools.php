<?php
declare(strict_types=1);

/**
 * Database hub helpers: owned/foreign table inventory, SQL dump/restore, CHECK/REPAIR/OPTIMIZE, DROP.
 * Agent/CLI callers must pass owned-only table lists; DROP is UI-only by convention.
 */
final class DbTools
{
    public const PREF_MODE = 'db_tools_mode';
    public const MODE_NORMAL = 'normal';
    public const MODE_EXPERT = 'expert';

    /** Max rows per INSERT (secondary cap; size target usually hits first). */
    private const INSERT_ROWS = 8000;
    /** Target uncompressed size per INSERT (under typical max_allowed_packet). */
    private const INSERT_BYTES = 1048576; // 1 MiB
    /** Rows cap per dump tick (Sypex uses 25000; we allow more within wall-clock budget). */
    public const ROW_CHUNK = 50000;
    /** Soft wall-clock budget per dump tick (seconds). Sypex time_web defaults to 600. */
    private const DUMP_TICK_SECONDS = 60.0;
    /** Flush gzip/plain write buffer at this size. */
    private const DUMP_WRITE_BUFFER = 61440; // Sypex flush threshold
    /** Bytes downloaded per fetch tick. */
    private const FETCH_CHUNK = 1024 * 1024;
    /** Max remote dump size (bytes). */
    private const FETCH_MAX_BYTES = 2147483648; // 2 GiB
    /** SQL statements soft cap per restore tick (also stopped by time budget). */
    public const RESTORE_STMT_CHUNK = 8000;
    /** Soft wall-clock budget per restore tick (seconds). */
    private const RESTORE_TICK_SECONDS = 240.0;
    /** Soft wall-clock for one-shot gunzip before SQL restore. */
    private const RESTORE_INFLATE_TICK_SECONDS = 120.0;
    /** Gzip level for finalize (1=fastest/largest … 9=slowest/smallest). */
    private const GZIP_LEVEL = 5;
    /** Max carry-over SQL buffer on disk (bytes) — protects against runaway statements. */
    private const RESTORE_BUFFER_MAX = 64 * 1024 * 1024;
    /** Sypex statement terminator — safe inside serialized strings; enables fast split. */
    private const STMT_END = "\t;\n";

    public static function dumpsDir(): string
    {
        $dir = base_path('var/db_dumps');
        if (!is_dir($dir)) {
            if (!is_dir(base_path('var'))) {
                @mkdir(base_path('var'), 0775, true);
            }
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Union of module.json tables[] from all discovered modules (may not exist in DB yet).
     * Use for restore into empty/partial schema.
     *
     * @return list<string>
     */
    public static function declaredTables(): array
    {
        $names = [];
        foreach (ModuleManager::all() as $meta) {
            $tables = $meta['tables'] ?? [];
            if (!is_array($tables)) {
                continue;
            }
            foreach ($tables as $t) {
                if (!is_string($t) || $t === '' || !self::isValidTableName($t)) {
                    continue;
                }
                $names[$t] = true;
            }
        }
        $out = array_keys($names);
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Union of module.json tables[] from all discovered modules that exist in the DB.
     *
     * @return list<string>
     */
    public static function ownedTables(): array
    {
        $out = [];
        foreach (self::declaredTables() as $t) {
            if (Database::tableExists($t)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * All base tables in the current connect database.
     *
     * @return list<string>
     */
    public static function allTables(): array
    {
        $st = Database::pdo()->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            $name = (string)($row[0] ?? '');
            if ($name !== '' && self::isValidTableName($name)) {
                $out[] = $name;
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * @return list<string>
     */
    public static function foreignTables(): array
    {
        $owned = array_fill_keys(self::ownedTables(), true);
        $out = [];
        foreach (self::allTables() as $t) {
            if (!isset($owned[$t])) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * Fast inventory via SHOW TABLE STATUS only (no CHECK TABLE).
     * InnoDB Rows are approximate. Size = Data_length + Index_length.
     *
     * @return list<array{
     *   table:string,
     *   owned:bool,
     *   rows:int,
     *   data_bytes:int,
     *   index_bytes:int,
     *   total_bytes:int,
     *   engine:string
     * }>
     */
    public static function tableInventory(): array
    {
        $owned = array_fill_keys(self::ownedTables(), true);
        $st = Database::pdo()->query('SHOW TABLE STATUS');
        $out = [];
        if ($st === false) {
            return [];
        }
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $name = (string)($row['Name'] ?? '');
            if ($name === '' || !self::isValidTableName($name)) {
                continue;
            }
            $data = (int)($row['Data_length'] ?? 0);
            $index = (int)($row['Index_length'] ?? 0);
            $out[] = [
                'table' => $name,
                'owned' => isset($owned[$name]),
                'rows' => (int)($row['Rows'] ?? 0),
                'data_bytes' => $data,
                'index_bytes' => $index,
                'total_bytes' => $data + $index,
                'engine' => (string)($row['Engine'] ?? ''),
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp($a['table'], $b['table']);
        });
        return $out;
    }

    public static function isValidTableName(string $name): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_]+$/', $name);
    }

    /**
     * @param list<string> $tables
     * @return list<string>
     */
    public static function filterValidTables(array $tables): array
    {
        $out = [];
        $seen = [];
        foreach ($tables as $t) {
            if (!is_string($t) || $t === '' || !self::isValidTableName($t)) {
                continue;
            }
            if (isset($seen[$t])) {
                continue;
            }
            $seen[$t] = true;
            $out[] = $t;
        }
        return $out;
    }

    /**
     * @param list<string> $tables requested
     * @param list<string> $allowed whitelist
     * @return list<string>
     */
    public static function intersectTables(array $tables, array $allowed): array
    {
        $allow = array_fill_keys($allowed, true);
        $out = [];
        foreach (self::filterValidTables($tables) as $t) {
            if (isset($allow[$t])) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $tables
     * @return array{ok:bool,file:string,path:string,bytes:int,tables:list<string>,gzip:bool}
     */
    public static function dump(array $tables, ?string $label = null): array
    {
        @set_time_limit(0);
        $job = self::jobStart('dump', [
            'tables' => self::filterValidTables($tables),
            'label' => $label,
        ]);
        $id = (string)$job['id'];
        while (($job['status'] ?? '') === 'running') {
            $job = self::jobTick($id);
        }
        if (($job['status'] ?? '') !== 'done') {
            throw new RuntimeException((string)($job['error'] ?? 'Dump failed'));
        }
        /** @var array{ok:bool,file:string,path:string,bytes:int,tables:list<string>,gzip:bool} $result */
        $result = $job['result'] ?? [];
        return $result;
    }

    /**
     * @param list<string> $allowedTables
     * @return array{ok:bool,statements:int,tables:list<string>}
     */
    public static function restore(string $file, array $allowedTables): array
    {
        @set_time_limit(0);
        $job = self::jobStart('restore', [
            'file' => $file,
            'allowed' => self::filterValidTables($allowedTables),
        ]);
        $id = (string)$job['id'];
        while (($job['status'] ?? '') === 'running') {
            $job = self::jobTick($id);
        }
        if (($job['status'] ?? '') !== 'done') {
            throw new RuntimeException((string)($job['error'] ?? 'Restore failed'));
        }
        /** @var array{ok:bool,statements:int,tables:list<string>} $result */
        $result = $job['result'] ?? [];
        return $result;
    }

    /**
     * @param list<string> $tables
     * @return list<array{table:string,Msg_text?:string,Msg_type?:string,status:string}>
     */
    public static function optimize(array $tables): array
    {
        return self::runMaintJob('optimize', $tables);
    }

    /**
     * @param list<string> $tables
     * @return list<array{table:string,Msg_text?:string,Msg_type?:string,status:string}>
     */
    public static function repair(array $tables): array
    {
        return self::runMaintJob('repair', $tables);
    }

    /**
     * @param list<string> $tables
     * @return list<array{table:string,Msg_text?:string,Msg_type?:string,status:string}>
     */
    private static function runMaintJob(string $op, array $tables): array
    {
        @set_time_limit(0);
        $job = self::jobStart($op, ['tables' => self::filterValidTables($tables)]);
        $id = (string)$job['id'];
        while (($job['status'] ?? '') === 'running') {
            $job = self::jobTick($id);
        }
        if (($job['status'] ?? '') !== 'done') {
            throw new RuntimeException((string)($job['error'] ?? $op . ' failed'));
        }
        /** @var list<array{table:string,Msg_text?:string,Msg_type?:string,status:string}> $report */
        $report = $job['result']['report'] ?? [];
        return $report;
    }

    // --- Chunked jobs (UI progress + fat DB safety) -------------------------

    public static function jobsDir(): string
    {
        $dir = base_path('var/db_jobs');
        if (!is_dir($dir)) {
            if (!is_dir(base_path('var'))) {
                @mkdir(base_path('var'), 0775, true);
            }
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function jobStart(string $op, array $opts = []): array
    {
        @set_time_limit(120);
        $op = strtolower(trim($op));
        if (!in_array($op, ['dump', 'restore', 'optimize', 'repair', 'check', 'diagnose', 'fetch'], true)) {
            throw new InvalidArgumentException('Unknown job op');
        }
        $id = date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $job = [
            'id' => $id,
            'op' => $op,
            'status' => 'running',
            'progress' => 0,
            'message' => '',
            'error' => null,
            'result' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        if ($op === 'dump') {
            $tables = self::filterValidTables($opts['tables'] ?? []);
            if ($tables === []) {
                throw new InvalidArgumentException('No tables to dump');
            }
            foreach ($tables as $t) {
                if (!Database::tableExists($t)) {
                    throw new RuntimeException('Table missing: ' . $t);
                }
            }
            $dir = self::dumpsDir();
            $stamp = date('Y-m-d_H-i-s');
            $willGzip = function_exists('gzopen');
            // Plain .sql during dump (fast, seekable). Single-member gzip at end (level 5).
            // Do NOT stream gzopen('ab') across ticks — multi-member gzip breaks restore offsets (1062).
            $basenameSql = $stamp . '.sql';
            $pathSql = $dir . '/' . $basenameSql;
            $header = "-- FreeSaaS DB dump\n"
                . '-- Generated: ' . date('c') . "\n"
                . '-- Database: ' . (string)Config::get('connect', 'dbase', '') . "\n"
                . '-- Tables: ' . implode(', ', $tables) . "\n"
                . '-- Compression: ' . ($willGzip ? ('gzip level ' . self::GZIP_LEVEL . ' (single-member finalize)') : 'none') . "\n"
                . "-- Statement end: TAB+semicolon (Sypex-compatible)\n\n"
                . 'SET NAMES utf8mb4' . self::STMT_END
                . 'SET FOREIGN_KEY_CHECKS=0' . self::STMT_END . "\n";
            self::dumpWriteChunk($pathSql, $header, false, true);

            $tableRows = self::estimateRowsMap($tables);
            $rowEst = array_sum($tableRows);
            $job['tables'] = $tables;
            $job['tables_total'] = count($tables);
            $job['file'] = $willGzip ? ($stamp . '.sql.gz') : $basenameSql;
            $job['path'] = $pathSql;
            $job['sql_path'] = $pathSql;
            $job['gzip'] = false;
            $job['will_gzip'] = $willGzip;
            $job['bytes_raw'] = strlen($header);
            $job['table_idx'] = 0;
            $job['phase'] = 'ddl';
            $job['offset'] = 0;
            $job['last_pk'] = null;
            $job['pk_col'] = null;
            $job['rows_done'] = 0;
            $job['rows_est'] = $rowEst;
            $job['rows_in_table'] = 0;
            $job['table_rows'] = $tableRows;
            $job['bytes'] = is_file($pathSql) ? (int)filesize($pathSql) : 0;
            $job['progress_overall'] = 0;
            $job['progress_table'] = 0;
            $job['elapsed'] = 0;
            $job['eta_seconds'] = null;
            $job['table'] = $tables[0] ?? '';
            $job['message'] = t('core.db.job.dump_start', ['n' => (string)count($tables)]);
        } elseif ($op === 'fetch') {
            $url = trim((string)($opts['url'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                throw new InvalidArgumentException(t('core.db.err.fetch_url'));
            }
            $parts = parse_url($url);
            if (!is_array($parts) || empty($parts['host'])) {
                throw new InvalidArgumentException(t('core.db.err.fetch_url'));
            }
            self::assertFetchableUrl($parts);
            $hostSafe = self::sanitizeHostMarker((string)$parts['host']);
            $pathPart = (string)($parts['path'] ?? '');
            $ext = '.sql.gz';
            if (preg_match('/\.sql$/i', $pathPart) && !preg_match('/\.sql\.gz$/i', $pathPart)) {
                $ext = '.sql';
            } elseif (preg_match('/\.sql\.gz$/i', $pathPart)) {
                $ext = '.sql.gz';
            }
            $basename = date('Y-m-d_H-i-s') . '-from-' . $hostSafe . $ext;
            $dest = self::dumpsDir() . '/' . $basename;
            $job['url'] = $url;
            $job['file'] = $basename;
            $job['path'] = $dest;
            $job['bytes_done'] = 0;
            $job['bytes_total'] = 0;
            $job['bytes'] = 0;
            $job['phase'] = 'fetch';
            $job['progress_overall'] = 0;
            $job['progress_table'] = 0;
            $job['elapsed'] = 0;
            $job['eta_seconds'] = null;
            $job['message'] = t('core.db.job.fetch_start', ['host' => $hostSafe]);
        } elseif ($op === 'restore') {
            $file = (string)($opts['file'] ?? '');
            $path = self::resolveDumpPath($file);
            // Prefer sibling plain .sql kept next to .sql.gz — skips inflate entirely.
            $restorePath = $path;
            $isGz = (bool)preg_match('/\.gz$/i', $path);
            if ($isGz) {
                $sibling = preg_replace('/\.gz$/i', '', $path);
                if (is_string($sibling) && $sibling !== $path && is_file($sibling)) {
                    $restorePath = $sibling;
                    $isGz = false;
                }
            }
            // Empty/partial DB: owned/allTables() may be empty — allow any valid name in dump.
            $allowed = self::filterValidTables($opts['allowed'] ?? []);
            foreach (self::declaredTables() as $t) {
                $allowed[] = $t;
            }
            $sqlSample = self::readDumpHead($restorePath, 2 * 1024 * 1024);
            foreach (self::extractReferencedTables($sqlSample) as $t) {
                if (self::isValidTableName($t)) {
                    $allowed[] = $t;
                }
            }
            $allowed = self::filterValidTables($allowed);
            $job['file'] = basename($path);
            $job['path'] = $restorePath;
            $job['source_path'] = $path;
            $job['allowed'] = $allowed;
            $job['allow_any_valid'] = true;
            $job['byte_offset'] = 0;
            $job['decomp_offset'] = 0;
            $job['gzip'] = $isGz;
            $job['statements'] = 0;
            $job['tables_done'] = 0;
            $job['fk_off'] = false;
            $job['table'] = '';
            $job['buffer_file'] = self::jobBufferPath((string)$job['id']);
            @unlink($job['buffer_file']);
            $metaBytes = self::readDumpMetaBytes($path);
            if ($metaBytes <= 0) {
                $metaBytes = self::readDumpMetaBytes($restorePath);
            }
            if ($isGz) {
                $inflated = self::dumpsDir() . '/.restore_' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$job['id']) . '.sql';
                @unlink($inflated);
                $job['inflated_path'] = $inflated;
                $job['inflate_bytes'] = 0;
                $job['phase'] = 'inflate';
                $job['byte_total'] = $metaBytes > 0 ? $metaBytes : max(1, (int)filesize($path) * 6);
                $job['bytes'] = (int)filesize($path);
                $job['message'] = t('core.db.job.restore_inflate');
            } else {
                $job['phase'] = 'sql';
                $job['byte_total'] = $metaBytes > 0 ? $metaBytes : max(1, (int)filesize($restorePath));
                $job['bytes'] = (int)filesize($restorePath);
                $job['message'] = t('core.db.job.restore_start');
            }
        } elseif ($op === 'diagnose') {
            $tables = self::filterValidTables($opts['tables'] ?? []);
            if ($tables === []) {
                throw new InvalidArgumentException('No tables to diagnose');
            }
            $owned = self::filterValidTables($opts['owned'] ?? self::ownedTables());
            $statusMap = [];
            try {
                $st = Database::pdo()->query('SHOW TABLE STATUS');
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $name = (string)($row['Name'] ?? '');
                    if ($name !== '') {
                        $statusMap[$name] = $row;
                    }
                }
            } catch (Throwable $e) {
                // continue with check-only
            }
            $job['tables'] = $tables;
            $job['owned'] = $owned;
            $job['status_map'] = $statusMap;
            $job['table_idx'] = 0;
            $job['rows'] = [];
            $job['message'] = t('core.db.job.diag_start', ['n' => (string)count($tables)]);
        } else {
            $tables = self::filterValidTables($opts['tables'] ?? []);
            if ($tables === []) {
                throw new InvalidArgumentException('No tables selected');
            }
            $job['tables'] = $tables;
            $job['table_idx'] = 0;
            $job['report'] = [];
            $job['message'] = t('core.db.job.maint_start', ['op' => strtoupper($op)]);
        }

        self::jobSave($job);
        return $job;
    }

    /** @return array<string,mixed> */
    public static function jobGet(string $id): array
    {
        $path = self::jobPath($id);
        if (!is_file($path)) {
            throw new RuntimeException('Job not found');
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            throw new RuntimeException('Corrupt job file');
        }
        return $data;
    }

    /** @return array<string,mixed> */
    public static function jobTick(string $id): array
    {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');
        $job = self::jobGet($id);
        if (($job['status'] ?? '') !== 'running') {
            return $job;
        }
        try {
            $op = (string)($job['op'] ?? '');
            if ($op === 'dump') {
                $job = self::tickDump($job);
            } elseif ($op === 'restore') {
                $job = self::tickRestore($job);
            } elseif ($op === 'diagnose') {
                $job = self::tickDiagnose($job);
            } elseif ($op === 'fetch') {
                $job = self::tickFetch($job);
            } elseif (in_array($op, ['optimize', 'repair', 'check'], true)) {
                $job = self::tickMaint($job);
            } else {
                throw new RuntimeException('Unknown job op');
            }
        } catch (Throwable $e) {
            $job['status'] = 'error';
            $job['error'] = $e->getMessage();
            $job['message'] = $e->getMessage();
            $job['updated_at'] = time();
            self::jobSave($job);
            return $job;
        }
        $job['updated_at'] = time();
        self::jobSave($job);
        return $job;
    }

    /** @return array<string,mixed> */
    public static function jobCancel(string $id): array
    {
        $job = self::jobGet($id);
        if (($job['status'] ?? '') !== 'running') {
            return $job;
        }
        $job['status'] = 'cancelled';
        $job['message'] = t('core.db.job.cancelled');
        $job['updated_at'] = time();
        $job['elapsed'] = max(0, time() - (int)($job['created_at'] ?? time()));
        // Drop incomplete artifacts.
        $opName = (string)($job['op'] ?? '');
        if (in_array($opName, ['dump', 'fetch'], true)) {
            foreach (['path', 'sql_path'] as $k) {
                $p = (string)($job[$k] ?? '');
                if ($p !== '' && is_file($p)) {
                    @unlink($p);
                }
            }
            $file = (string)($job['file'] ?? '');
            if ($file !== '') {
                $cand = self::dumpsDir() . '/' . basename($file);
                if (is_file($cand)) {
                    @unlink($cand);
                }
            }
            $job['bytes'] = 0;
        }
        if ($opName === 'restore') {
            foreach (['inflated_path', 'buffer_file'] as $k) {
                $p = (string)($job[$k] ?? '');
                if ($p !== '' && is_file($p)) {
                    @unlink($p);
                }
            }
            $buf = self::jobBufferPath((string)($job['id'] ?? ''));
            if ($buf !== '' && is_file($buf)) {
                @unlink($buf);
            }
        }
        self::jobSave($job);
        return $job;
    }

    /**
     * Slim job payload for JSON UI.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function jobPublic(array $job): array
    {
        return [
            'id' => (string)($job['id'] ?? ''),
            'op' => (string)($job['op'] ?? ''),
            'status' => (string)($job['status'] ?? ''),
            'progress' => (int)($job['progress'] ?? 0),
            'progress_overall' => (int)($job['progress_overall'] ?? $job['progress'] ?? 0),
            'progress_table' => (int)($job['progress_table'] ?? 0),
            'message' => (string)($job['message'] ?? ''),
            'error' => $job['error'] ?? null,
            'result' => $job['result'] ?? null,
            'elapsed' => (int)($job['elapsed'] ?? 0),
            'eta_seconds' => isset($job['eta_seconds']) ? (int)$job['eta_seconds'] : null,
            'bytes' => (int)($job['bytes'] ?? 0),
            'table' => (string)($job['table'] ?? ''),
            'table_idx' => (int)($job['table_idx'] ?? 0),
            'tables_total' => (int)($job['tables_total'] ?? (is_array($job['tables'] ?? null) ? count($job['tables']) : 0)),
            'phase' => (string)($job['phase'] ?? ''),
            'file' => (string)($job['file'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $job */
    private static function jobSave(array $job): void
    {
        $id = (string)($job['id'] ?? '');
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new RuntimeException('Invalid job id');
        }
        // Never persist SQL carry-over in JSON (binary / multi‑MB → encode failures).
        unset($job['buffer'], $job['status_map']);
        $path = self::jobPath($id);
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $json = json_encode($job, $flags);
        if ($json === false) {
            // Last resort: drop bulky keys and retry.
            unset($job['result'], $job['rows'], $job['table_rows'], $job['report']);
            $json = json_encode($job, $flags);
        }
        if ($json === false) {
            throw new RuntimeException('Cannot encode job');
        }
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write job');
        }
    }

    private static function jobBufferPath(string $id): string
    {
        $id = basename($id);
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            return '';
        }
        return self::jobsDir() . '/' . $id . '.buf';
    }

    /** @param array<string,mixed> $job */
    private static function jobBufferRead(array $job): string
    {
        $path = (string)($job['buffer_file'] ?? self::jobBufferPath((string)($job['id'] ?? '')));
        if ($path === '' || !is_file($path)) {
            return '';
        }
        $raw = file_get_contents($path);
        return is_string($raw) ? $raw : '';
    }

    /** @param array<string,mixed> $job */
    private static function jobBufferWrite(array $job, string $buffer): void
    {
        $path = (string)($job['buffer_file'] ?? self::jobBufferPath((string)($job['id'] ?? '')));
        if ($path === '') {
            return;
        }
        if ($buffer === '') {
            @unlink($path);
            return;
        }
        if (strlen($buffer) > self::RESTORE_BUFFER_MAX) {
            throw new RuntimeException(
                'Restore statement buffer exceeds ' . (int)(self::RESTORE_BUFFER_MAX / 1024 / 1024) . ' MB'
            );
        }
        if (file_put_contents($path, $buffer, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write restore buffer');
        }
    }

    private static function readDumpMetaBytes(string $dumpPath): int
    {
        $meta = $dumpPath . '.meta';
        if (!is_file($meta)) {
            return 0;
        }
        $raw = file_get_contents($meta);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return 0;
        }
        return max(0, (int)($data['uncompressed_bytes'] ?? 0));
    }

    private static function writeDumpMeta(string $gzPath, int $uncompressedBytes): void
    {
        $meta = $gzPath . '.meta';
        @file_put_contents($meta, json_encode([
            'uncompressed_bytes' => $uncompressedBytes,
            'created_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function jobPath(string $id): string
    {
        $id = basename($id);
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new InvalidArgumentException('Invalid job id');
        }
        return self::jobsDir() . '/' . $id . '.json';
    }

    /**
     * @param list<string> $tables
     * @return array<string,int> table => approx rows
     */
    private static function estimateRowsMap(array $tables): array
    {
        $out = array_fill_keys($tables, 0);
        try {
            $st = Database::pdo()->query('SHOW TABLE STATUS');
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $name = (string)($row['Name'] ?? '');
                if (isset($out[$name])) {
                    $out[$name] = max(0, (int)($row['Rows'] ?? 0));
                }
            }
        } catch (Throwable $e) {
            // leave zeros
        }
        return $out;
    }

    /**
     * @param list<string> $tables
     */
    private static function estimateRows(array $tables): int
    {
        return (int)array_sum(self::estimateRowsMap($tables));
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function attachProgressMeta(array $job, float $overallFrac, float $tableFrac): array
    {
        $overallFrac = max(0.0, min(1.0, $overallFrac));
        $tableFrac = max(0.0, min(1.0, $tableFrac));
        $status = (string)($job['status'] ?? '');
        if ($status === 'done') {
            $overallFrac = 1.0;
            $tableFrac = 1.0;
        }
        $job['progress_overall'] = (int)floor($overallFrac * 100);
        $job['progress_table'] = (int)floor($tableFrac * 100);
        $job['progress'] = (int)$job['progress_overall'];
        $elapsed = max(0, time() - (int)($job['created_at'] ?? time()));
        $job['elapsed'] = $elapsed;
        // Restore tracks uncompressed cursor in bytes; don't clobber with gz filesize.
        if ((string)($job['op'] ?? '') !== 'restore') {
            $path = (string)($job['path'] ?? '');
            $job['bytes'] = ($path !== '' && is_file($path)) ? (int)filesize($path) : (int)($job['bytes'] ?? 0);
        }
        if ($status === 'running' && $overallFrac >= 0.02 && $elapsed >= 3) {
            $job['eta_seconds'] = (int)max(0, (int)round(($elapsed / $overallFrac) - $elapsed));
        } else {
            $job['eta_seconds'] = null;
        }
        return $job;
    }

    public static function sanitizeHostMarker(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/[^a-z0-9.-]+/', '', $host) ?? '';
        $host = trim($host, '.-');
        if ($host === '') {
            return 'remote';
        }
        if (strlen($host) > 80) {
            $host = substr($host, 0, 80);
        }
        return $host;
    }

    /** Public site origin for absolute agent URLs (respects X-Forwarded-*). */
    public static function publicOrigin(): string
    {
        $fwdProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $https = $fwdProto === 'https'
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
        if (strpos($host, ',') !== false) {
            $host = trim(explode(',', $host, 2)[0]);
        }
        $host = preg_replace('/[^a-zA-Z0-9.:\[\]-]+/', '', $host) ?? 'localhost';
        if ($host === '') {
            $host = 'localhost';
        }
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    /**
     * Absolute fetch URL with short-lived HMAC sig for paste into another instance «Fetch by URL».
     * Null when agent_token is not configured or file name is invalid.
     * Does not put the raw agent_token in the query string.
     */
    public static function agentDumpFetchUrl(string $file): ?string
    {
        // 1h TTL: cross-host fetch may stream large dumps with Range retries.
        $signed = AgentAuth::signDumpFile($file, 3600);
        if ($signed === null) {
            return null;
        }
        $file = basename(str_replace(["\0", '\\'], '', $file));
        return self::publicOrigin() . '/agent/db/dump/file?file=' . rawurlencode($file)
            . '&exp=' . (string)$signed['exp']
            . '&sig=' . rawurlencode($signed['sig']);
    }

    private static function dumpWriteChunk(string $path, string $chunk, bool $gzip, bool $create): void
    {
        if ($gzip) {
            $lvl = (string)self::GZIP_LEVEL;
            $mode = ($create || !is_file($path) || filesize($path) === 0) ? ('wb' . $lvl) : ('ab' . $lvl);
            $fh = gzopen($path, $mode);
            if ($fh === false) {
                throw new RuntimeException('Cannot open gzip dump file');
            }
            if (gzwrite($fh, $chunk) === false) {
                gzclose($fh);
                throw new RuntimeException('Cannot write gzip dump file');
            }
            gzclose($fh);
            return;
        }
        $mode = ($create || !is_file($path)) ? 'wb' : 'ab';
        $fh = fopen($path, $mode);
        if ($fh === false) {
            throw new RuntimeException('Cannot open dump file');
        }
        if (fwrite($fh, $chunk) === false) {
            fclose($fh);
            throw new RuntimeException('Cannot write dump file');
        }
        fclose($fh);
    }

    /**
     * Open dump handle for one tick (gzip member or plain append).
     *
     * @return resource
     */
    private static function dumpOpenTick(string $path, bool $gzip)
    {
        if ($gzip) {
            $lvl = (string)self::GZIP_LEVEL;
            $mode = (!is_file($path) || filesize($path) === 0) ? ('wb' . $lvl) : ('ab' . $lvl);
            $fh = gzopen($path, $mode);
            if ($fh === false) {
                throw new RuntimeException('Cannot open gzip dump file');
            }
            return $fh;
        }
        $fh = fopen($path, 'ab');
        if ($fh === false) {
            throw new RuntimeException('Cannot append dump file');
        }
        return $fh;
    }

    /** @param resource $fh */
    private static function dumpCloseTick($fh, bool $gzip): void
    {
        if ($gzip) {
            gzclose($fh);
        } elseif (is_resource($fh)) {
            fclose($fh);
        }
    }

    /** @param resource $fh */
    private static function dumpWriteTick($fh, string $chunk, bool $gzip): void
    {
        if ($gzip) {
            if (gzwrite($fh, $chunk) === false) {
                throw new RuntimeException('Cannot write dump file');
            }
            return;
        }
        if (fwrite($fh, $chunk) === false) {
            throw new RuntimeException('Cannot write dump file');
        }
    }

    private static function primaryKeyColumn(string $table): ?string
    {
        $st = Database::pdo()->query(
            'SHOW KEYS FROM `' . str_replace('`', '``', $table) . '` WHERE Key_name = \'PRIMARY\''
        );
        $cols = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $seq = (int)($row['Seq_in_index'] ?? 0);
            $cols[$seq] = (string)($row['Column_name'] ?? '');
        }
        ksort($cols);
        $cols = array_values(array_filter($cols));
        return count($cols) === 1 ? $cols[0] : null;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function tickDump(array $job): array
    {
        $tables = $job['tables'] ?? [];
        if (!is_array($tables) || $tables === []) {
            throw new RuntimeException('Dump job missing tables');
        }
        $path = (string)($job['sql_path'] ?? $job['path'] ?? '');
        $idx = (int)($job['table_idx'] ?? 0);
        $nTables = count($tables);
        $job['tables_total'] = $nTables;
        $phase = (string)($job['phase'] ?? 'ddl');
        // Body is always plain SQL; optional single-member gzip is a final phase.
        $gzip = false;
        $job['path'] = $path;

        if ($phase === 'gzip') {
            return self::tickGzip($job);
        }

        if (($idx > 0 || $phase === 'data' || $phase === 'finalize') && $path !== '' && !is_file($path)) {
            throw new RuntimeException('Dump file missing; start a new dump');
        }
        $pdo = Database::pdo();
        @set_time_limit(600);
        @ini_set('default_socket_timeout', '7200');
        try {
            $pdo->exec('SET SESSION net_read_timeout=7200');
            $pdo->exec('SET SESSION net_write_timeout=7200');
        } catch (Throwable $e) {
            // ignore
        }
        $deadline = microtime(true) + self::DUMP_TICK_SECONDS;
        $unbuffered = false;
        $fh = null;
        /** @var (callable(bool):void)|null $flushWrite */
        $flushWrite = null;
        $rawWritten = 0;

        try {
            if ($phase === 'finalize' || $idx >= $nTables) {
                $job['phase'] = 'finalize';
                $job['message'] = t('core.db.job.dump_finalize');
                $job['table'] = '';
                $job = self::attachProgressMeta($job, 0.99, 1.0);
                $tail = 'SET FOREIGN_KEY_CHECKS=1' . self::STMT_END;
                $fh = self::dumpOpenTick($path, false);
                self::dumpWriteTick($fh, $tail, false);
                self::dumpCloseTick($fh, false);
                $fh = null;
                $job['bytes_raw'] = (int)($job['bytes_raw'] ?? 0) + strlen($tail);
                if (!empty($job['will_gzip'])) {
                    $gzPath = preg_replace('/\.sql$/i', '.sql.gz', $path) ?: ($path . '.gz');
                    $job['phase'] = 'gzip';
                    $job['gz_path'] = $gzPath;
                    $job['gzip_offset'] = 0;
                    $job['gzip_total'] = (int)filesize($path);
                    $job['message'] = t('core.db.job.dump_gzip', ['level' => (string)self::GZIP_LEVEL]);
                    $job['table'] = '';
                    return self::attachProgressMeta($job, 0.99, 1.0);
                }
                $bytes = is_file($path) ? (int)filesize($path) : 0;
                $job['status'] = 'done';
                $job['phase'] = 'done';
                $job['file'] = basename($path);
                $job['message'] = t('core.db.job.dump_done', [
                    'file' => (string)$job['file'],
                    'bytes' => self::formatBytesLabel($bytes),
                    'raw' => self::formatBytesLabel((int)($job['bytes_raw'] ?? $bytes)),
                    'level' => '0',
                    'elapsed' => self::formatDurationLabel((int)(time() - (int)($job['created_at'] ?? time()))),
                ]);
                $job['result'] = [
                    'ok' => true,
                    'file' => (string)$job['file'],
                    'path' => $path,
                    'bytes' => $bytes,
                    'bytes_raw' => (int)$job['bytes_raw'],
                    'tables' => $tables,
                    'gzip' => false,
                    'elapsed' => max(0, time() - (int)($job['created_at'] ?? time())),
                ];
                return self::attachProgressMeta($job, 1.0, 1.0);
            }

            $table = (string)$tables[$idx];
            $job['table'] = $table;
            $tableEst = (int)(($job['table_rows'][$table] ?? 0));
            if ($tableEst <= 0) {
                $tableEst = 1;
            }

                $fh = self::dumpOpenTick($path, $gzip);
                $writeBuf = '';
                $flushWrite = function (bool $force = false) use (&$writeBuf, $fh, $gzip, &$rawWritten): void {
                    if ($writeBuf === '') {
                        return;
                    }
                    if (!$force && strlen($writeBuf) < self::DUMP_WRITE_BUFFER) {
                        return;
                    }
                    $rawWritten += strlen($writeBuf);
                    self::dumpWriteTick($fh, $writeBuf, $gzip);
                    $writeBuf = '';
                };
                $write = function (string $chunk) use (&$writeBuf, $flushWrite): void {
                    $writeBuf .= $chunk;
                    $flushWrite(false);
                };

            if ($phase === 'ddl') {
                $createSt = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`');
                $createRow = $createSt->fetch(PDO::FETCH_NUM);
                $createSql = (string)($createRow[1] ?? '');
                if ($createSql === '') {
                    throw new RuntimeException('SHOW CREATE failed for ' . $table);
                }
                $write("-- ----------------------------\n");
                $write('-- Table structure: `' . $table . "`\n");
                $write("-- ----------------------------\n");
                $write('DROP TABLE IF EXISTS `' . $table . '`' . self::STMT_END);
                $write($createSql . self::STMT_END . "\n");
                $write("-- ----------------------------\n");
                $write('-- Data: `' . $table . "`\n");
                $write("-- ----------------------------\n");
                $job['phase'] = 'data';
                $job['offset'] = 0;
                $job['last_pk'] = null;
                $job['pk_col'] = self::primaryKeyColumn($table);
                $job['rows_in_table'] = 0;
                $job['message'] = t('core.db.job.dump_ddl', [
                    'table' => $table,
                    'i' => (string)($idx + 1),
                    'n' => (string)$nTables,
                ]);
                $overall = ($idx + 0.05) / max(1, $nTables);
                $flushWrite(true);
                $job['bytes_raw'] = (int)($job['bytes_raw'] ?? 0) + $rawWritten;
                $job['bytes'] = is_file($path) ? (int)filesize($path) : (int)$job['bytes_raw'];
                return self::attachProgressMeta($job, $overall, 0.0);
            }

            $pkCol = isset($job['pk_col']) && is_string($job['pk_col']) && $job['pk_col'] !== ''
                ? $job['pk_col'] : null;
            $limit = self::ROW_CHUNK;
            $escaped = str_replace('`', '``', $table);

            if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $unbuffered = true;
            }

            if ($pkCol !== null) {
                $pkEsc = str_replace('`', '``', $pkCol);
                $lastPk = $job['last_pk'] ?? null;
                if ($lastPk === null) {
                    $st = $pdo->query(
                        'SELECT * FROM `' . $escaped . '` ORDER BY `' . $pkEsc . '` ASC LIMIT ' . $limit
                    );
                } else {
                    $st = $pdo->prepare(
                        'SELECT * FROM `' . $escaped . '` WHERE `' . $pkEsc . '` > ? ORDER BY `' . $pkEsc . '` ASC LIMIT ' . $limit
                    );
                    $st->execute([$lastPk]);
                }
            } else {
                $offset = (int)($job['offset'] ?? 0);
                $st = $pdo->query(
                    'SELECT * FROM `' . $escaped . '` LIMIT ' . $limit . ' OFFSET ' . $offset
                );
            }
            if ($st === false) {
                throw new RuntimeException('SELECT failed for ' . $table);
            }

            $batch = [];
            $batchBytes = 0;
            $cols = null;
            $fetched = 0;
            $lastPkVal = $job['last_pk'] ?? null;
            $hitDeadline = false;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === null) {
                    $cols = array_keys($row);
                }
                $batch[] = $row;
                $fetched++;
                if ($pkCol !== null) {
                    $lastPkVal = $row[$pkCol] ?? $lastPkVal;
                }
                // Rough size: prefer flushing by payload size (Sypex ~64KB write chunks).
                $batchBytes += 64;
                foreach ($row as $v) {
                    if ($v === null) {
                        $batchBytes += 4;
                    } elseif (is_scalar($v)) {
                        $batchBytes += strlen((string)$v) + 3;
                    }
                }
                if (count($batch) >= self::INSERT_ROWS || $batchBytes >= self::INSERT_BYTES) {
                    $write(self::formatInsert($table, $cols, $batch));
                    $batch = [];
                    $batchBytes = 0;
                }
                if ($fetched >= 50 && microtime(true) >= $deadline) {
                    $hitDeadline = true;
                    break;
                }
            }
            $st->closeCursor();
            if ($unbuffered) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                $unbuffered = false;
            }
            if ($batch !== [] && $cols !== null) {
                $write(self::formatInsert($table, $cols, $batch));
            }

            $job['rows_done'] = (int)($job['rows_done'] ?? 0) + $fetched;
            $job['rows_in_table'] = (int)($job['rows_in_table'] ?? 0) + $fetched;
            if ($pkCol !== null) {
                $job['last_pk'] = $lastPkVal;
            } else {
                $job['offset'] = (int)($job['offset'] ?? 0) + $fetched;
            }

            $tableDone = !$hitDeadline && $fetched < $limit;
            $tableFrac = $tableDone
                ? 1.0
                : min(0.99, ((int)$job['rows_in_table']) / max(1, $tableEst));
            $overall = ($idx + $tableFrac) / max(1, $nTables);
            if ($overall >= 0.99 && !$tableDone) {
                $overall = 0.989;
            }

            $job['message'] = t('core.db.job.dump_rows', [
                'table' => $table,
                'rows' => (string)$job['rows_in_table'],
                'chunk' => (string)$fetched,
                'i' => (string)($idx + 1),
                'n' => (string)$nTables,
            ]);

            if ($tableDone) {
                $write("\n");
                $job['table_idx'] = $idx + 1;
                $job['phase'] = ($idx + 1) >= $nTables ? 'finalize' : 'ddl';
                $job['offset'] = 0;
                $job['last_pk'] = null;
                $job['pk_col'] = null;
                $job['rows_in_table'] = 0;
                if (($job['phase'] ?? '') === 'finalize') {
                    $overall = 0.99;
                    $job['message'] = t('core.db.job.dump_finalize');
                }
            }

            $flushWrite(true);
            $job['bytes_raw'] = (int)($job['bytes_raw'] ?? 0) + $rawWritten;
            $job['bytes'] = is_file($path) ? (int)filesize($path) : (int)$job['bytes_raw'];
            return self::attachProgressMeta($job, $overall, $tableFrac);
        } finally {
            if (isset($flushWrite) && is_callable($flushWrite)) {
                try {
                    $flushWrite(true);
                } catch (Throwable $e) {
                    // ignore
                }
            }
            if ($unbuffered) {
                try {
                    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                } catch (Throwable $e) {
                    // ignore
                }
            }
            if ($fh !== null) {
                try {
                    self::dumpCloseTick($fh, $gzip);
                } catch (Throwable $e) {
                    // ignore
                }
            }
        }
    }

    /**
     * Reject session-only /db?action=download links (no cookies on server fetch).
     *
     * @param array<string,mixed> $parts parse_url() result
     */
    private static function assertFetchableUrl(array $parts): void
    {
        $query = [];
        parse_str((string)($parts['query'] ?? ''), $query);
        if ((string)($query['action'] ?? '') !== 'download') {
            return;
        }
        $file = (string)($query['file'] ?? 'NAME.sql.gz');
        if ($file === '' || !preg_match('/\.sql(\.gz)?$/i', $file)) {
            $file = 'NAME.sql.gz';
        }
        $scheme = (string)($parts['scheme'] ?? 'https');
        $host = (string)($parts['host'] ?? 'example.com');
        $hint = $scheme . '://' . $host . '/agent/db/dump/file?file='
            . rawurlencode($file) . '&exp=…&sig=…';
        throw new InvalidArgumentException(t('core.db.err.fetch_session_url', ['hint' => $hint]));
    }

    /**
     * @return array{ok:bool,code:int,headers:string,body:string,errno:int,error:string}
     */
    private static function httpFetchChunk(string $url, int $bytesDone, int $maxRead): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'code' => 0, 'headers' => '', 'body' => '', 'errno' => -1, 'error' => 'curl_init failed'];
            }
            $headers = '';
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_USERAGENT => 'FreeSaaS-DbFetch/1.0',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                    $headers .= $line;
                    return strlen($line);
                },
            ]);
            if ($bytesDone > 0) {
                curl_setopt($ch, CURLOPT_RANGE, $bytesDone . '-');
            }
            // Cap body read via write callback stop — CURLOPT_RANGE already limits start;
            // still limit per-tick bytes with a write function.
            $body = '';
            $stopped = false;
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use (&$body, &$stopped, $maxRead): int {
                if ($stopped) {
                    return 0; // abort
                }
                $body .= $chunk;
                if (strlen($body) >= $maxRead) {
                    $body = substr($body, 0, $maxRead);
                    $stopped = true;
                    return 0;
                }
                return strlen($chunk);
            });
            $ok = curl_exec($ch);
            $errno = (int)curl_errno($ch);
            $error = (string)curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // Aborting via write fn yields CURLE_WRITE_ERROR — treat as success if we have bytes.
            if ($stopped && ($errno === 23 || $errno === 0) && $body !== '') {
                $ok = true;
                $errno = 0;
                $error = '';
            }
            curl_close($ch);
            return [
                'ok' => $ok !== false && $errno === 0,
                'code' => $code,
                'headers' => $headers,
                'body' => $body,
                'errno' => $errno,
                'error' => $error,
            ];
        }

        $reqHeaders = "User-Agent: FreeSaaS-DbFetch/1.0\r\n";
        if ($bytesDone > 0) {
            $reqHeaders .= "Range: bytes={$bytesDone}-\r\n";
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 25,
                'header' => $reqHeaders,
                'follow_location' => 1,
                'max_redirects' => 5,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $remote = @fopen($url, 'rb', false, $ctx);
        if ($remote === false) {
            $last = error_get_last();
            return [
                'ok' => false,
                'code' => 0,
                'headers' => '',
                'body' => '',
                'errno' => -1,
                'error' => is_array($last) ? (string)($last['message'] ?? 'fopen failed') : 'fopen failed',
            ];
        }
        $wrapper = [];
        $meta = stream_get_meta_data($remote);
        if (is_array($meta['wrapper_data'] ?? null)) {
            $wrapper = $meta['wrapper_data'];
        }
        $headerText = '';
        $code = 0;
        foreach ($wrapper as $line) {
            if (!is_string($line)) {
                continue;
            }
            $headerText .= $line . "\r\n";
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $m)) {
                $code = (int)$m[1];
            }
        }
        // Full response when Range ignored — skip already-downloaded bytes.
        $isPartial = $code === 206;
        if ($bytesDone > 0 && !$isPartial) {
            $skipped = 0;
            while ($skipped < $bytesDone && !feof($remote)) {
                $need = min(65536, $bytesDone - $skipped);
                $chunk = fread($remote, $need);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $skipped += strlen($chunk);
            }
        }
        $body = '';
        $deadline = microtime(true) + 8.0;
        while (!feof($remote) && strlen($body) < $maxRead && microtime(true) < $deadline) {
            $chunk = fread($remote, min(256 * 1024, $maxRead - strlen($body)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        $eofHint = feof($remote);
        fclose($remote);
        // Encode eof in headers comment for caller (hack-free: use trailing marker).
        if ($eofHint) {
            $headerText .= "X-FreeSaaS-Eof: 1\r\n";
        }
        return [
            'ok' => true,
            'code' => $code,
            'headers' => $headerText,
            'body' => $body,
            'errno' => 0,
            'error' => '',
        ];
    }

    private static function throwFetchHttpError(int $code, string $detail = ''): void
    {
        if ($code === 401 || $code === 403) {
            throw new RuntimeException(t('core.db.err.fetch_auth', [
                'code' => (string)$code,
            ]));
        }
        if ($code === 404) {
            throw new RuntimeException(t('core.db.err.fetch_not_found'));
        }
        $msg = t('core.db.err.fetch_open');
        if ($code > 0) {
            $msg .= ' (HTTP ' . $code . ')';
        }
        if ($detail !== '') {
            $msg .= ': ' . $detail;
        }
        throw new RuntimeException($msg);
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function tickFetch(array $job): array
    {
        $url = (string)($job['url'] ?? '');
        $path = (string)($job['path'] ?? '');
        if ($url === '' || $path === '') {
            throw new RuntimeException('Fetch job incomplete');
        }

        $bytesDone = (int)($job['bytes_done'] ?? 0);
        $bytesTotal = (int)($job['bytes_total'] ?? 0);

        if (!isset($job['stream']) || !is_array($job['stream'])) {
            $job['stream'] = ['opened' => true];
            if (!is_file($path)) {
                $touch = fopen($path, 'wb');
                if ($touch === false) {
                    throw new RuntimeException('Cannot create local dump file');
                }
                fclose($touch);
            }
        }

        $maxRead = 2 * 1024 * 1024; // ~2 MiB per tick
        $resp = self::httpFetchChunk($url, $bytesDone, $maxRead);
        $code = (int)$resp['code'];
        // 416 = already have the full file via Range past EOF.
        if ($code === 416 && $bytesDone > 0) {
            $job['status'] = 'done';
            $job['phase'] = 'done';
            $job['bytes'] = $bytesDone;
            $job['message'] = t('core.db.job.fetch_done', [
                'file' => (string)$job['file'],
                'bytes' => self::formatBytesLabel($bytesDone),
            ]);
            $job['result'] = [
                'ok' => true,
                'file' => (string)$job['file'],
                'path' => $path,
                'bytes' => $bytesDone,
                'from' => self::sanitizeHostMarker((string)(parse_url((string)$job['url'], PHP_URL_HOST) ?: 'remote')),
            ];
            unset($job['stream']);
            return self::attachProgressMeta($job, 1.0, 1.0);
        }
        if (!$resp['ok'] || ($code >= 400 && $code !== 0)) {
            if (is_file($path) && $bytesDone === 0) {
                @unlink($path);
            }
            self::throwFetchHttpError($code > 0 ? $code : 0, (string)$resp['error']);
        }
        if ($code !== 0 && $code !== 200 && $code !== 206) {
            if (is_file($path) && $bytesDone === 0) {
                @unlink($path);
            }
            self::throwFetchHttpError($code);
        }

        // Content-Length / Content-Range for total size (first successful tick).
        if ($bytesTotal <= 0 && preg_match('/^Content-Range:\s*bytes\s+\d+-\d+\/(\d+)/mi', $resp['headers'], $m)) {
            $bytesTotal = (int)$m[1];
        } elseif ($bytesTotal <= 0 && $bytesDone === 0
            && preg_match('/^Content-Length:\s*(\d+)/mi', $resp['headers'], $m)) {
            $bytesTotal = (int)$m[1];
        }
        $job['bytes_total'] = max(0, $bytesTotal);

        $chunk = (string)$resp['body'];
        $read = strlen($chunk);
        if ($read > 0) {
            $out = fopen($path, 'ab');
            if ($out === false) {
                throw new RuntimeException('Cannot append local dump file');
            }
            fwrite($out, $chunk);
            fclose($out);
        }
        if (($bytesDone + $read) > self::FETCH_MAX_BYTES) {
            @unlink($path);
            throw new RuntimeException(t('core.db.err.fetch_too_large'));
        }

        // EOF: stream feof, empty body, Content-Length reached, or cURL finished under cap.
        $eof = false;
        if (stripos($resp['headers'], 'X-FreeSaaS-Eof: 1') !== false) {
            $eof = true;
        } elseif ($read === 0) {
            $eof = true;
        } elseif ($bytesTotal > 0 && ($bytesDone + $read) >= $bytesTotal) {
            $eof = true;
        } elseif ($read < $maxRead) {
            // Transfer ended before the per-tick cap (cURL completed or stream feof).
            $eof = true;
        }

        $bytesDone += $read;
        $job['bytes_done'] = $bytesDone;
        $job['bytes'] = $bytesDone;
        if ($bytesTotal > 0) {
            $frac = min(0.99, $bytesDone / $bytesTotal);
        } else {
            $frac = $eof ? 1.0 : min(0.95, $bytesDone / max(1, $bytesDone + self::FETCH_CHUNK));
        }
        $job['message'] = t('core.db.job.fetch_prog', [
            'bytes' => self::formatBytesLabel($bytesDone),
            'total' => $bytesTotal > 0 ? self::formatBytesLabel($bytesTotal) : '…',
        ]);

        if ($eof || ($bytesTotal > 0 && $bytesDone >= $bytesTotal)) {
            $job['status'] = 'done';
            $job['phase'] = 'done';
            $job['message'] = t('core.db.job.fetch_done', [
                'file' => (string)$job['file'],
                'bytes' => self::formatBytesLabel($bytesDone),
            ]);
            $job['result'] = [
                'ok' => true,
                'file' => (string)$job['file'],
                'path' => $path,
                'bytes' => $bytesDone,
                'from' => self::sanitizeHostMarker((string)(parse_url((string)$job['url'], PHP_URL_HOST) ?: 'remote')),
            ];
            unset($job['stream']);
            return self::attachProgressMeta($job, 1.0, 1.0);
        }

        return self::attachProgressMeta($job, $frac, $frac);
    }

    private static function formatBytesLabel(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / (1024 * 1024), 2) . ' MB';
    }

    private static function formatDurationLabel(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        if ($m <= 0) {
            return $s . 's';
        }
        return $m . 'm ' . sprintf('%02d', $s) . 's';
    }

    /**
     * Legacy post-dump gzip (unused when streaming .sql.gz). Kept for CLI fallbacks.
     *
     * @return array{file:string,path:string,bytes:int,gzip:bool}
     */
    private static function maybeGzipDump(string $sqlPath): array
    {
        if (!function_exists('gzopen') || !is_file($sqlPath)) {
            return [
                'file' => basename($sqlPath),
                'path' => $sqlPath,
                'bytes' => (int)filesize($sqlPath),
                'gzip' => false,
            ];
        }
        $gzPath = $sqlPath . '.gz';
        $in = fopen($sqlPath, 'rb');
        $out = gzopen($gzPath, 'wb9');
        if ($in === false || $out === false) {
            return [
                'file' => basename($sqlPath),
                'path' => $sqlPath,
                'bytes' => (int)filesize($sqlPath),
                'gzip' => false,
            ];
        }
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            gzwrite($out, $chunk);
        }
        fclose($in);
        gzclose($out);
        @unlink($sqlPath);
        return [
            'file' => basename($gzPath),
            'path' => $gzPath,
            'bytes' => (int)filesize($gzPath),
            'gzip' => true,
        ];
    }

    private static function readDumpHead(string $path, int $maxBytes): string
    {
        if (preg_match('/\.gz$/i', $path)) {
            $zh = gzopen($path, 'rb');
            if ($zh === false) {
                return '';
            }
            $data = gzread($zh, $maxBytes);
            gzclose($zh);
            return is_string($data) ? $data : '';
        }
        $raw = file_get_contents($path, false, null, 0, $maxBytes);
        return is_string($raw) ? $raw : '';
    }

    /**
     * @param list<string> $allowed
     */
    private static function assertDumpTablesAllowed(string $path, array $allowed): void
    {
        $allow = array_fill_keys($allowed, true);
        $blocked = [];
        $sql = '';
        if (preg_match('/\.gz$/i', $path)) {
            $zh = gzopen($path, 'rb');
            if ($zh === false) {
                throw new RuntimeException('Cannot read dump');
            }
            while (!gzeof($zh)) {
                $chunk = gzread($zh, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $sql .= $chunk;
                if (strlen($sql) > 8 * 1024 * 1024) {
                    foreach (self::extractReferencedTables($sql) as $t) {
                        if (!isset($allow[$t])) {
                            $blocked[$t] = true;
                        }
                    }
                    $sql = substr($sql, -65536);
                }
            }
            gzclose($zh);
        } else {
            $fh = fopen($path, 'rb');
            if ($fh === false) {
                throw new RuntimeException('Cannot read dump');
            }
            while (!feof($fh)) {
                $chunk = fread($fh, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $sql .= $chunk;
                if (strlen($sql) > 8 * 1024 * 1024) {
                    foreach (self::extractReferencedTables($sql) as $t) {
                        if (!isset($allow[$t])) {
                            $blocked[$t] = true;
                        }
                    }
                    $sql = substr($sql, -65536);
                }
            }
            fclose($fh);
        }
        if ($sql !== '') {
            foreach (self::extractReferencedTables($sql) as $t) {
                if (!isset($allow[$t])) {
                    $blocked[$t] = true;
                }
            }
        }
        if ($blocked !== []) {
            throw new RuntimeException(
                'Dump references tables outside allow-list: ' . implode(', ', array_slice(array_keys($blocked), 0, 20))
            );
        }
    }

    /**
     * Compress finished .sql into a single-member .sql.gz (one pass).
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function tickGzip(array $job): array
    {
        $sqlPath = (string)($job['sql_path'] ?? $job['path'] ?? '');
        $gzPath = (string)($job['gz_path'] ?? '');
        if ($sqlPath === '' || !is_file($sqlPath)) {
            throw new RuntimeException('SQL dump missing for gzip');
        }
        if ($gzPath === '') {
            $gzPath = preg_replace('/\.sql$/i', '.sql.gz', $sqlPath) ?: ($sqlPath . '.gz');
            $job['gz_path'] = $gzPath;
        }
        $job['message'] = t('core.db.job.dump_gzip', ['level' => (string)self::GZIP_LEVEL]);
        $job['table'] = '';
        $job = self::attachProgressMeta($job, 0.99, 1.0);
        $sqlBytes = (int)filesize($sqlPath);

        @set_time_limit(300);
        $ok = false;
        $lvl = (int)self::GZIP_LEVEL;
        // Prefer system gzip: single member. Level 5 ≪ 9 (avoid 90s+ finalize).
        $gzipBin = is_file('/usr/bin/gzip') ? '/usr/bin/gzip' : (is_file('/bin/gzip') ? '/bin/gzip' : '');
        if ($gzipBin !== '') {
            $cmd = $gzipBin . ' -' . $lvl . ' -c ' . escapeshellarg($sqlPath) . ' > ' . escapeshellarg($gzPath);
            $proc = proc_open(
                ['/bin/sh', '-c', $cmd],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                null
            );
            if (is_resource($proc)) {
                fclose($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $code = proc_close($proc);
                $ok = ($code === 0 && is_file($gzPath) && filesize($gzPath) > 0);
                if (!$ok) {
                    @unlink($gzPath);
                    if (is_string($err) && trim($err) !== '') {
                        // fall through to PHP zlib
                    }
                }
            }
        }
        if (!$ok) {
            $in = fopen($sqlPath, 'rb');
            $out = gzopen($gzPath, 'wb' . $lvl);
            if ($in === false || $out === false) {
                throw new RuntimeException('Cannot gzip dump');
            }
            while (!feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                gzwrite($out, $chunk);
                $job['bytes'] = is_file($gzPath) ? (int)filesize($gzPath) : 0;
            }
            fclose($in);
            gzclose($out);
        }
        self::writeDumpMeta($gzPath, $sqlBytes);
        @unlink($sqlPath);

        $bytes = is_file($gzPath) ? (int)filesize($gzPath) : 0;
        $job['path'] = $gzPath;
        $job['sql_path'] = $gzPath;
        $job['file'] = basename($gzPath);
        $job['gzip'] = true;
        $job['status'] = 'done';
        $job['phase'] = 'done';
        $job['message'] = t('core.db.job.dump_done', [
            'file' => (string)$job['file'],
            'bytes' => self::formatBytesLabel($bytes),
            'elapsed' => self::formatDurationLabel((int)(time() - (int)($job['created_at'] ?? time()))),
            'level' => (string)self::GZIP_LEVEL,
            'raw' => self::formatBytesLabel($sqlBytes),
        ]);
        $job['result'] = [
            'ok' => true,
            'file' => (string)$job['file'],
            'path' => $gzPath,
            'bytes' => $bytes,
            'bytes_raw' => $sqlBytes,
            'gzip_level' => self::GZIP_LEVEL,
            'tables' => $job['tables'] ?? [],
            'gzip' => true,
            'elapsed' => max(0, time() - (int)($job['created_at'] ?? time())),
        ];
        return self::attachProgressMeta($job, 1.0, 1.0);
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function tickRestore(array $job): array
    {
        @set_time_limit(600);
        if ((string)($job['phase'] ?? '') === 'inflate') {
            return self::tickRestoreInflate($job);
        }
        return self::tickRestoreSql($job);
    }

    /**
     * Gunzip once to a seekable .sql (avoids O(n²) skip on every HTTP tick).
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function tickRestoreInflate(array $job): array
    {
        @set_time_limit(600);
        $gzPath = (string)($job['source_path'] ?? $job['path'] ?? '');
        $outPath = (string)($job['inflated_path'] ?? '');
        if ($gzPath === '' || !is_file($gzPath) || $outPath === '') {
            throw new RuntimeException('Restore inflate paths missing');
        }

        $written = (int)($job['inflate_bytes'] ?? 0);
        $est = max(1, (int)($job['byte_total'] ?? 1));
        $deadline = microtime(true) + self::RESTORE_INFLATE_TICK_SECONDS;

        if ($written === 0) {
            $bin = is_file('/usr/bin/gzip') ? '/usr/bin/gzip'
                : (is_file('/bin/gzip') ? '/bin/gzip' : '');
            if ($bin === '' && is_file('/usr/bin/gunzip')) {
                $bin = '/usr/bin/gunzip';
            }
            if ($bin !== '') {
                $cmd = $bin . ' -dc ' . escapeshellarg($gzPath) . ' > ' . escapeshellarg($outPath);
                $out = [];
                $code = 1;
                @exec($cmd, $out, $code);
                if ($code === 0 && is_file($outPath) && filesize($outPath) > 0) {
                    return self::restoreInflateFinish($job, $outPath);
                }
                @unlink($outPath);
            }
        }

        $gz = gzopen($gzPath, 'rb');
        if ($gz === false) {
            throw new RuntimeException('Cannot open gzip dump');
        }
        $left = $written;
        while ($left > 0 && !gzeof($gz)) {
            $skip = gzread($gz, min(1024 * 1024, $left));
            if ($skip === false || $skip === '') {
                break;
            }
            $left -= strlen($skip);
        }
        $out = fopen($outPath, $written > 0 ? 'ab' : 'wb');
        if ($out === false) {
            gzclose($gz);
            throw new RuntimeException('Cannot write inflated dump');
        }
        $eof = false;
        try {
            while (microtime(true) < $deadline) {
                if (gzeof($gz)) {
                    $eof = true;
                    break;
                }
                $chunk = gzread($gz, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    $eof = gzeof($gz);
                    break;
                }
                if (fwrite($out, $chunk) === false) {
                    throw new RuntimeException('Inflate write failed');
                }
                $written += strlen($chunk);
            }
            $eof = $eof || gzeof($gz);
        } finally {
            fclose($out);
            gzclose($gz);
        }

        $job['inflate_bytes'] = $written;
        $job['bytes'] = $written;
        if ($written > $est) {
            $job['byte_total'] = $written;
            $est = $written;
        }
        $frac = min(0.18, 0.02 + 0.16 * ($written / max(1, $est)));
        $job['message'] = t('core.db.job.restore_inflate_prog', [
            'bytes' => self::formatBytesLabel($written),
        ]);
        $job = self::attachProgressMeta($job, $frac, min(0.95, $written / max(1, $est)));
        if ($eof) {
            return self::restoreInflateFinish($job, $outPath);
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function restoreInflateFinish(array $job, string $outPath): array
    {
        clearstatcache(true, $outPath);
        $size = is_file($outPath) ? (int)filesize($outPath) : 0;
        if ($size <= 0) {
            throw new RuntimeException('Inflated dump is empty');
        }
        $job['path'] = $outPath;
        $job['gzip'] = false;
        $job['phase'] = 'sql';
        $job['byte_offset'] = 0;
        $job['decomp_offset'] = 0;
        $job['byte_total'] = $size;
        $job['inflate_bytes'] = $size;
        $job['bytes'] = $size;
        $job['message'] = t('core.db.job.restore_start');
        return self::attachProgressMeta($job, 0.2, 0.0);
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function tickRestoreSql(array $job): array
    {
        @set_time_limit(600);
        $path = (string)($job['path'] ?? '');
        $allowed = self::filterValidTables($job['allowed'] ?? []);
        // Reconnect after laptop sleep / mysqld restart (SQLSTATE 2002).
        $pdo = Database::ensureConnected();
        self::restorePrepareSession($pdo);
        $job['fk_off'] = true;

        if (empty($job['buffer_file'])) {
            $job['buffer_file'] = self::jobBufferPath((string)($job['id'] ?? ''));
        }

        // After inflate, path is plain .sql (gzip=false). Plain fseek is instant.
        $isGz = !empty($job['gzip']);
        $buffer = self::jobBufferRead($job);
        $total = max(1, (int)($job['byte_total'] ?? 0));
        if (!$isGz && is_file($path)) {
            $fs = (int)filesize($path);
            if ($fs > $total) {
                $total = $fs;
                $job['byte_total'] = $fs;
            }
        }
        $deadline = microtime(true) + self::RESTORE_TICK_SECONDS;
        $executed = 0;
        $eof = false;
        $pos = $isGz ? (int)($job['decomp_offset'] ?? 0) : (int)($job['byte_offset'] ?? 0);
        $stmtCap = self::RESTORE_STMT_CHUNK;
        $keysTable = (string)($job['keys_disabled'] ?? '');

        if ($isGz) {
            $fh = gzopen($path, 'rb');
            if ($fh === false) {
                throw new RuntimeException('Cannot open dump');
            }
            if ($pos > 0) {
                $left = $pos;
                while ($left > 0 && !gzeof($fh)) {
                    $skip = gzread($fh, min(1024 * 1024, $left));
                    if ($skip === false || $skip === '') {
                        break;
                    }
                    $left -= strlen($skip);
                }
            }
            $readChunk = static function () use ($fh) {
                if (gzeof($fh)) {
                    return false;
                }
                $c = gzread($fh, 61440);
                return ($c === false || $c === '') ? false : $c;
            };
            $isEof = static function () use ($fh): bool {
                return gzeof($fh);
            };
            $close = static function () use ($fh): void {
                gzclose($fh);
            };
        } else {
            $fh = fopen($path, 'rb');
            if ($fh === false) {
                throw new RuntimeException('Cannot open dump');
            }
            if ($pos > 0) {
                fseek($fh, $pos);
            }
            $readChunk = static function () use ($fh) {
                if (feof($fh)) {
                    return false;
                }
                $c = fread($fh, 1024 * 1024);
                return ($c === false || $c === '') ? false : $c;
            };
            $isEof = static function () use ($fh): bool {
                return feof($fh);
            };
            $close = static function () use ($fh): void {
                fclose($fh);
            };
        }

        $allow = array_fill_keys($allowed, true);
        $allowAny = !empty($job['allow_any_valid']);
        $currentTable = (string)($job['table'] ?? '');
        $tablesDone = (int)($job['tables_done'] ?? 0);
        if ($currentTable === '' && $buffer !== '') {
            $peek = self::statementPrimaryTable($buffer);
            if ($peek !== null) {
                $currentTable = $peek;
            }
        }

        $enableKeys = static function (PDO $pdo, string $table): void {
            if ($table === '') {
                return;
            }
            try {
                $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ENABLE KEYS');
            } catch (Throwable $e) {
                // InnoDB ignores DISABLE/ENABLE KEYS
            }
        };
        $disableKeys = static function (PDO $pdo, string $table): void {
            if ($table === '') {
                return;
            }
            try {
                $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` DISABLE KEYS');
            } catch (Throwable $e) {
                // ignore
            }
        };

        try {
            while (microtime(true) < $deadline && $executed < $stmtCap) {
                $chunk = $readChunk();
                if ($chunk === false) {
                    $eof = true;
                    break;
                }
                $pos += strlen($chunk);
                $buffer .= $chunk;

                [$parts, $buffer] = self::splitSqlStatements($buffer);

                foreach ($parts as $stmt) {
                    $stmt = trim($stmt);
                    if ($stmt === '') {
                        continue;
                    }
                    $tname = self::statementPrimaryTable($stmt);
                    if ($tname !== null) {
                        if (!self::restoreTableAllowed($tname, $allow, $allowAny)) {
                            throw new RuntimeException('Dump references invalid table name: ' . $tname);
                        }
                        if ($currentTable !== '' && $tname !== $currentTable
                            && preg_match('/^DROP\s+TABLE/i', $stmt)
                        ) {
                            $tablesDone++;
                        }
                        // Sypex: DISABLE KEYS around bulk INSERT (helps MyISAM).
                        if (preg_match('/^INSERT\s+/i', $stmt) && $keysTable !== $tname) {
                            if ($keysTable !== '') {
                                $enableKeys($pdo, $keysTable);
                            }
                            $disableKeys($pdo, $tname);
                            $keysTable = $tname;
                        }
                        if (preg_match('/^DROP\s+TABLE/i', $stmt) && $keysTable === $tname) {
                            $keysTable = '';
                        }
                        $currentTable = $tname;
                    }
                    self::restoreExec($pdo, $stmt);
                    $executed++;
                    $job['statements'] = (int)($job['statements'] ?? 0) + 1;
                }

                if ($currentTable === '' && $buffer !== '') {
                    $peek = self::statementPrimaryTable($buffer);
                    if ($peek !== null) {
                        $currentTable = $peek;
                    }
                }

                if ($executed >= $stmtCap || microtime(true) >= $deadline) {
                    break;
                }

                if (strlen($buffer) > self::RESTORE_BUFFER_MAX) {
                    throw new RuntimeException(
                        'Restore statement buffer exceeds '
                        . (int)(self::RESTORE_BUFFER_MAX / 1024 / 1024) . ' MB'
                    );
                }
            }
            $eof = $eof || $isEof();

            if ($eof && $buffer !== '') {
                [$parts, $buffer] = self::splitSqlStatements($buffer);
                if (trim($buffer) !== '') {
                    $parts[] = $buffer;
                    $buffer = '';
                }
                foreach ($parts as $stmt) {
                    $stmt = trim($stmt);
                    if ($stmt === '') {
                        continue;
                    }
                    $tname = self::statementPrimaryTable($stmt);
                    if ($tname !== null) {
                        if (!self::restoreTableAllowed($tname, $allow, $allowAny)) {
                            throw new RuntimeException('Dump references invalid table name: ' . $tname);
                        }
                        if (preg_match('/^INSERT\s+/i', $stmt) && $keysTable !== $tname) {
                            if ($keysTable !== '') {
                                $enableKeys($pdo, $keysTable);
                            }
                            $disableKeys($pdo, $tname);
                            $keysTable = $tname;
                        }
                        $currentTable = $tname;
                    }
                    self::restoreExec($pdo, $stmt);
                    $executed++;
                    $job['statements'] = (int)($job['statements'] ?? 0) + 1;
                }
            }
            if ($executed > 0) {
                try {
                    $pdo->exec('COMMIT');
                } catch (Throwable $e) {
                    // ignore
                }
            }
        } finally {
            $close();
        }

        if ($isGz) {
            $job['decomp_offset'] = $pos;
        } else {
            $job['byte_offset'] = $pos;
        }
        $job['table'] = $currentTable;
        $job['tables_done'] = $tablesDone;
        $job['keys_disabled'] = $keysTable;
        self::jobBufferWrite($job, $buffer);
        unset($job['buffer']);

        // Inflate used 0–20%; SQL maps file onto 20–99%.
        $base = !empty($job['inflated_path']) ? 0.2 : 0.0;
        $span = 1.0 - $base;
        if ($pos > $total) {
            $total = $pos + max(1, strlen($buffer));
            $job['byte_total'] = $total;
        }
        $frac = ($eof && $buffer === '')
            ? 1.0
            : min(0.99, $base + $span * ($pos / max(1, $total)));
        $tableFrac = ($eof && $buffer === '')
            ? 1.0
            : min(0.95, 0.2 + min(0.7, $executed / 50));
        $job = self::attachProgressMeta($job, $frac, $tableFrac);
        $job['bytes'] = $pos;
        $job['message'] = t('core.db.job.restore_prog', [
            'n' => (string)(int)($job['statements'] ?? 0),
            'pct' => (string)(int)$job['progress_overall'],
            'table' => $currentTable !== '' ? $currentTable : '—',
        ]);

        if ($eof && $buffer === '') {
            if ($keysTable !== '') {
                $enableKeys($pdo, $keysTable);
            }
            try {
                $pdo->exec('COMMIT');
            } catch (Throwable $e) {
                // ignore
            }
            $pdo->exec('SET UNIQUE_CHECKS=1');
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $pdo->exec('SET AUTOCOMMIT=1');
            Database::clearSchemaCache();
            SchemaMigrator::clearSetupStatusCache();
            self::jobBufferWrite($job, '');
            $inflated = (string)($job['inflated_path'] ?? '');
            if ($inflated !== '' && is_file($inflated)) {
                @unlink($inflated);
            }
            $job['status'] = 'done';
            $job['phase'] = 'done';
            $job['message'] = t('core.db.msg.restore_ok', ['n' => (string)(int)($job['statements'] ?? 0)]);
            $job['result'] = [
                'ok' => true,
                'statements' => (int)($job['statements'] ?? 0),
                'tables' => [],
            ];
            return self::attachProgressMeta($job, 1.0, 1.0);
        }
        return $job;
    }

    /** @param array<string,bool> $allow */
    private static function restoreTableAllowed(string $tname, array $allow, bool $allowAnyValid): bool
    {
        if (!self::isValidTableName($tname)) {
            return false;
        }
        if ($allowAnyValid) {
            return true;
        }
        return isset($allow[$tname]);
    }

    /** Apply Sypex-like bulk-load session settings (call every tick after reconnect). */
    private static function restorePrepareSession(PDO $pdo): void
    {
        try {
            $pdo->exec("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
        } catch (Throwable $e) {
            // ignore
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('SET UNIQUE_CHECKS=0');
        try {
            $pdo->exec('SET SQL_NOTES=0');
        } catch (Throwable $e) {
            // ignore
        }
        try {
            $pdo->exec('SET SESSION max_allowed_packet=67108864');
        } catch (Throwable $e) {
            // ignore
        }
        $pdo->exec('SET AUTOCOMMIT=0');
        try {
            $pdo->exec('SET SESSION sql_log_bin=0');
        } catch (Throwable $e) {
            // ignore
        }
    }

    /**
     * Execute restore statement; ignore MySQL truncation/notes warnings (Sypex continues).
     * Reconnect once on lost connection (2002/2006/2013) and replace $pdo for the caller.
     */
    private static function restoreExec(PDO &$pdo, string $stmt): void
    {
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            $state = (string)$e->getCode();
            $msg = $e->getMessage();
            if ($state === '01000' || $state === '01004'
                || strpos($msg, '1265') !== false
                || stripos($msg, 'Data truncated') !== false
                || (stripos($msg, 'Warning:') !== false && stripos($msg, 'Incorrect ') !== false)
            ) {
                return;
            }
            if (strpos($msg, '2002') !== false || strpos($msg, '2006') !== false
                || strpos($msg, '2013') !== false || stripos($msg, 'server has gone away') !== false
                || stripos($msg, 'No such file or directory') !== false
            ) {
                Database::reset();
                $pdo = Database::ensureConnected();
                self::restorePrepareSession($pdo);
                $pdo->exec($stmt);
                return;
            }
            throw $e;
        }
    }

    /** Table name from DDL/DML head only (never scan huge INSERT payloads). */
    private static function statementPrimaryTable(string $stmt): ?string
    {
        $head = substr(ltrim($stmt), 0, 180);
        if (preg_match(
            '/^(?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|TRUNCATE\s+(?:TABLE\s+)?|ALTER\s+TABLE|DELETE\s+FROM|UPDATE)\s+`?([A-Za-z0-9_]+)`?/i',
            $head,
            $m
        )) {
            return (string)$m[1];
        }
        return null;
    }

    private static function tickMaint(array $job): array
    {
        $op = (string)($job['op'] ?? 'check');
        $tables = $job['tables'] ?? [];
        if (!is_array($tables)) {
            $tables = [];
        }
        $idx = (int)($job['table_idx'] ?? 0);
        $n = count($tables);
        if ($idx >= $n) {
            $job['status'] = 'done';
            $job['progress'] = 100;
            $job['message'] = t('core.db.msg.maint_done', ['op' => strtoupper($op)]);
            $job['result'] = ['ok' => true, 'report' => $job['report'] ?? []];
            return $job;
        }
        $table = (string)$tables[$idx];
        $job['message'] = t('core.db.job.maint_table', ['op' => strtoupper($op), 'table' => $table]);
        if ($op === 'check') {
            $rows = self::runTableOp('CHECK', [$table]);
        } elseif ($op === 'repair') {
            $rows = self::runTableOp('REPAIR', [$table]);
        } else {
            $rows = self::runTableOp('OPTIMIZE', [$table]);
        }
        $report = $job['report'] ?? [];
        if (!is_array($report)) {
            $report = [];
        }
        foreach ($rows as $r) {
            $report[] = $r;
        }
        $job['report'] = $report;
        $job['table_idx'] = $idx + 1;
        $job['progress'] = (int)floor((($idx + 1) / max(1, $n)) * 100);
        if ($job['table_idx'] >= $n) {
            $job['status'] = 'done';
            $job['progress'] = 100;
            $job['message'] = t('core.db.msg.maint_done', ['op' => strtoupper($op)]);
            $job['result'] = ['ok' => true, 'report' => $report];
        }
        return $job;
    }

    /**
     * Legacy one-shot dump body kept only via job loop — formatInsert helpers below.
     *
     * @param list<string> $cols
     * @param list<array<string,mixed>> $rows
     */
    private static function formatInsert(string $table, array $cols, array $rows): string
    {
        // Sypex-style: VALUES only (column order matches CREATE / SELECT *).
        $valueGroups = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ($cols as $c) {
                $vals[] = self::sqlLiteral($row[$c] ?? null);
            }
            $valueGroups[] = '(' . implode(',', $vals) . ')';
        }
        return 'INSERT INTO `' . $table . '` VALUES' . "\n"
            . implode(",\n", $valueGroups) . self::STMT_END;
    }

    /** @param mixed $value */
    private static function sqlLiteral($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return (string)$value;
        }
        $s = (string)$value;
        // Binary / non-utf8 → hex (avoid PDO::quote during unbuffered dump cursors).
        if (!mb_check_encoding($s, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $s)) {
            return '0x' . bin2hex($s);
        }
        return "'" . str_replace(
            ["\\", "\0", "\n", "\r", "'", '"', "\x1a"],
            ["\\\\", '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $s
        ) . "'";
    }

    /**
     * @return list<array{file:string,bytes:int,mtime:int,stamp:string,label:string}>
     */
    public static function listDumps(): array
    {
        $dir = self::dumpsDir();
        if (!is_dir($dir)) {
            return [];
        }
        $names = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (preg_match('/\.sql(\.gz)?$/i', $name)) {
                $names[$name] = true;
            }
        }
        $out = [];
        foreach (array_keys($names) as $name) {
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $stamp = '';
            $extra = '';
            if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})(?:-(.+))?\.sql(\.gz)?$/i', $name, $m)) {
                $stamp = $m[1] . ' ' . $m[2] . ':' . $m[3] . ':' . $m[4];
                $extra = isset($m[5]) ? (string)$m[5] : '';
            } elseif (preg_match('/^(\d{14})(?:-(.+))?\.sql(\.gz)?$/i', $name, $m)) {
                $raw = $m[1];
                $stamp = substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2)
                    . ' ' . substr($raw, 8, 2) . ':' . substr($raw, 10, 2) . ':' . substr($raw, 12, 2);
                $extra = isset($m[2]) ? (string)$m[2] : '';
            }
            $out[] = [
                'file' => $name,
                'bytes' => (int)filesize($path),
                'mtime' => (int)filemtime($path),
                'stamp' => $stamp,
                'label' => $extra,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return ($b['mtime'] <=> $a['mtime']) ?: strcmp($a['file'], $b['file']);
        });
        return $out;
    }

    public static function resolveDumpPath(string $file): string
    {
        $file = basename(str_replace(["\0", '\\'], '', $file));
        if ($file === '' || $file === '.' || $file === '..') {
            throw new InvalidArgumentException('Invalid dump file name');
        }
        if (!preg_match('/^[A-Za-z0-9._-]+\.sql(\.gz)?$/i', $file)) {
            throw new InvalidArgumentException('Invalid dump file name');
        }
        $path = self::dumpsDir() . '/' . $file;
        $realDir = realpath(self::dumpsDir());
        $realFile = realpath($path);
        if ($realDir === false || $realFile === false || strpos($realFile, $realDir . DIRECTORY_SEPARATOR) !== 0) {
            throw new RuntimeException('Dump file not found');
        }
        if (!is_file($realFile)) {
            throw new RuntimeException('Dump file not found');
        }
        return $realFile;
    }

    public static function deleteDump(string $file): void
    {
        $path = self::resolveDumpPath($file);
        $siblings = [$path];
        if (preg_match('/\.sql\.gz$/i', $path)) {
            $siblings[] = preg_replace('/\.gz$/i', '', $path);
        } elseif (preg_match('/\.sql$/i', $path)) {
            $siblings[] = $path . '.gz';
        }
        $deleted = false;
        foreach ($siblings as $p) {
            if (!is_string($p) || $p === '' || !is_file($p)) {
                continue;
            }
            if (@unlink($p)) {
                $deleted = true;
            }
            $meta = $p . '.meta';
            if (is_file($meta)) {
                @unlink($meta);
            }
        }
        if (!$deleted) {
            throw new RuntimeException('Failed to delete dump');
        }
    }

    /**
     * Save a browser-uploaded .sql / .sql.gz into var/db_dumps/ (does not restore).
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file $_FILES item
     * @return array{ok:bool,file:string,path:string,bytes:int}
     */
    public static function saveUploadedDump(array $file): array
    {
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException(t('core.db.err.upload_missing'));
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException(t('core.db.err.upload_too_large', [
                'limit' => self::phpUploadLimitLabel(),
            ]));
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(t('core.db.err.upload_failed', ['code' => (string)$err]));
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException(t('core.db.err.upload_failed', ['code' => 'tmp']));
        }
        if ($size <= 0) {
            throw new InvalidArgumentException(t('core.db.err.upload_empty'));
        }
        if ($size > self::FETCH_MAX_BYTES) {
            throw new InvalidArgumentException(t('core.db.err.upload_too_large', [
                'limit' => '2 GiB',
            ]));
        }

        $orig = basename(str_replace(["\0", '\\'], '', (string)($file['name'] ?? '')));
        $ext = '';
        if (preg_match('/\.sql\.gz$/i', $orig)) {
            $ext = '.sql.gz';
        } elseif (preg_match('/\.sql$/i', $orig)) {
            $ext = '.sql';
        } else {
            throw new InvalidArgumentException(t('core.db.err.upload_type'));
        }

        $head = (string)@file_get_contents($tmp, false, null, 0, 16);
        if ($ext === '.sql.gz') {
            if (strlen($head) < 2 || $head[0] !== "\x1f" || $head[1] !== "\x8b") {
                throw new InvalidArgumentException(t('core.db.err.upload_type'));
            }
        } else {
            $probe = ltrim($head);
            if ($probe === '' || ($probe[0] !== '-' && $probe[0] !== '/' && strncasecmp($probe, 'DROP', 4) !== 0
                && strncasecmp($probe, 'CREATE', 6) !== 0 && strncasecmp($probe, 'INSERT', 6) !== 0
                && strncasecmp($probe, 'SET', 3) !== 0 && strncasecmp($probe, '/*!', 3) !== 0)) {
                throw new InvalidArgumentException(t('core.db.err.upload_type'));
            }
        }

        $label = (string)preg_replace('/\.sql(\.gz)?$/i', '', $orig);
        $label = (string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $label);
        $label = trim($label, '.-_');
        if ($label === '') {
            $label = 'local';
        }
        if (strlen($label) > 80) {
            $label = substr($label, 0, 80);
        }

        $basename = date('Y-m-d_H-i-s') . '-upload-' . $label . $ext;
        if (!preg_match('/^[A-Za-z0-9._-]+\.sql(\.gz)?$/i', $basename)) {
            throw new InvalidArgumentException(t('core.db.err.upload_type'));
        }

        $dir = self::dumpsDir();
        $dest = $dir . '/' . $basename;
        if (is_file($dest)) {
            $basename = date('Y-m-d_H-i-s') . '-upload-' . $label . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . $ext;
            $dest = $dir . '/' . $basename;
        }

        if (!@move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException(t('core.db.err.upload_save'));
        }
        @chmod($dest, 0664);

        return [
            'ok' => true,
            'file' => $basename,
            'path' => $dest,
            'bytes' => (int)filesize($dest),
        ];
    }

    /** Effective browser-upload cap from PHP ini (and 2 GiB hard max), human-readable. */
    public static function phpUploadLimitLabel(): string
    {
        $bytes = self::phpUploadLimitBytes();
        if ($bytes >= 1073741824) {
            return rtrim(rtrim(number_format($bytes / 1073741824, 1, '.', ''), '0'), '.') . ' GiB';
        }
        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' MiB';
        }
        return (string)max(1, (int)round($bytes / 1024)) . ' KiB';
    }

    public static function phpUploadLimitBytes(): int
    {
        $upload = self::iniBytes('upload_max_filesize');
        $post = self::iniBytes('post_max_size');
        $lim = self::FETCH_MAX_BYTES;
        if ($upload > 0) {
            $lim = min($lim, $upload);
        }
        if ($post > 0) {
            $lim = min($lim, $post);
        }
        return max(1, $lim);
    }

    private static function iniBytes(string $key): int
    {
        $raw = trim((string)ini_get($key));
        if ($raw === '' || $raw === '0') {
            return 0;
        }
        $n = (float)$raw;
        $u = strtolower(substr($raw, -1));
        if ($u === 'g') {
            $n *= 1024 * 1024 * 1024;
        } elseif ($u === 'm') {
            $n *= 1024 * 1024;
        } elseif ($u === 'k') {
            $n *= 1024;
        }
        return (int)$n;
    }

    private static function readDumpContents(string $path): string
    {
        if (preg_match('/\.gz$/i', $path)) {
            $raw = file_get_contents('compress.zlib://' . $path);
        } else {
            $raw = file_get_contents($path);
        }
        if ($raw === false) {
            throw new RuntimeException('Cannot read dump file');
        }
        return $raw;
    }

    /**
     * @return list<string>
     */
    public static function extractReferencedTables(string $sql): array
    {
        $found = [];
        $patterns = [
            '/\bDROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i',
            '/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i',
            '/\bINSERT\s+(?:IGNORE\s+)?INTO\s+`?([A-Za-z0-9_]+)`?/i',
            '/\bREPLACE\s+INTO\s+`?([A-Za-z0-9_]+)`?/i',
            '/\bTRUNCATE\s+(?:TABLE\s+)?`?([A-Za-z0-9_]+)`?/i',
            '/\bALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i',
            '/\bDELETE\s+FROM\s+`?([A-Za-z0-9_]+)`?/i',
            '/\bUPDATE\s+`?([A-Za-z0-9_]+)`?/i',
        ];
        foreach ($patterns as $re) {
            if (preg_match_all($re, $sql, $m)) {
                foreach ($m[1] as $name) {
                    if (self::isValidTableName($name)) {
                        $found[$name] = true;
                    }
                }
            }
        }
        $out = array_keys($found);
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Split SQL into complete statements; leftover returned separately.
     * Prefer Sypex `\t;` terminator (fast). Fallback: quote-aware `;` split.
     *
     * @return array{0: list<string>, 1: string}
     */
    private static function splitSqlStatements(string $sql): array
    {
        if (strpos($sql, "\t;") !== false) {
            return self::splitSqlByTabSemicolon($sql);
        }
        return self::splitSqlQuoteAware($sql);
    }

    /**
     * Fast path: statements end with TAB+semicolon (never appears inside SQL string literals we emit).
     *
     * @return array{0: list<string>, 1: string}
     */
    private static function splitSqlByTabSemicolon(string $sql): array
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $stmts = [];
        $offset = 0;
        $len = strlen($sql);
        while ($offset < $len) {
            $pos = strpos($sql, "\t;", $offset);
            if ($pos === false) {
                return [$stmts, substr($sql, $offset)];
            }
            $stmt = substr($sql, $offset, $pos - $offset);
            $offset = $pos + 2;
            if ($offset < $len && $sql[$offset] === "\n") {
                $offset++;
            }
            if (trim($stmt) !== '') {
                $stmts[] = $stmt;
            }
        }
        return [$stmts, ''];
    }

    /**
     * Quote-aware `;` split for foreign dumps without `\t;` terminators.
     *
     * @return array{0: list<string>, 1: string}
     */
    private static function splitSqlQuoteAware(string $sql): array
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $stmts = [];
        $buf = '';
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($ch === "\n") {
                    $inLineComment = false;
                    $buf .= $ch;
                }
                continue;
            }
            if ($inBlockComment) {
                if ($ch === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if ($inSingle) {
                $buf .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $buf .= $next;
                    $i++;
                    continue;
                }
                if ($ch === "'" && $next === "'") {
                    $buf .= $next;
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    $inSingle = false;
                }
                continue;
            }
            if ($inDouble) {
                $buf .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $buf .= $next;
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $inDouble = false;
                }
                continue;
            }
            if ($inBacktick) {
                $buf .= $ch;
                if ($ch === '`' && $next === '`') {
                    $buf .= $next;
                    $i++;
                    continue;
                }
                if ($ch === '`') {
                    $inBacktick = false;
                }
                continue;
            }

            if ($ch === '-' && $next === '-') {
                $inLineComment = true;
                continue;
            }
            if ($ch === '#') {
                $inLineComment = true;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
            if ($ch === "'") {
                $inSingle = true;
                $buf .= $ch;
                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                $buf .= $ch;
                continue;
            }
            if ($ch === '`') {
                $inBacktick = true;
                $buf .= $ch;
                continue;
            }
            if ($ch === ';') {
                $trim = trim($buf);
                if ($trim !== '') {
                    $stmts[] = $buf;
                }
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }

        return [$stmts, $buf];
    }

    /** @return list<string> complete statements only (legacy helper) */
    private static function splitSqlStatementsAll(string $sql): array
    {
        [$stmts, $tail] = self::splitSqlStatements($sql);
        if (trim($tail) !== '') {
            $stmts[] = $tail;
        }
        return $stmts;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function tickDiagnose(array $job): array
    {
        $tables = $job['tables'] ?? [];
        if (!is_array($tables)) {
            $tables = [];
        }
        $idx = (int)($job['table_idx'] ?? 0);
        $n = count($tables);
        if ($idx >= $n) {
            $job['status'] = 'done';
            $job['progress'] = 100;
            $job['message'] = t('core.db.job.diag_done');
            $job['result'] = ['ok' => true, 'diag' => $job['rows'] ?? []];
            return $job;
        }
        $table = (string)$tables[$idx];
        $owned = self::filterValidTables($job['owned'] ?? []);
        $statusMap = is_array($job['status_map'] ?? null) ? $job['status_map'] : [];
        $job['message'] = t('core.db.job.diag_table', ['table' => $table]);

        $checkOk = true;
        $texts = [];
        foreach (self::runTableOp('CHECK', [$table]) as $r) {
            $msgType = strtolower((string)($r['Msg_type'] ?? ''));
            $msgText = (string)($r['Msg_text'] ?? '');
            if ($msgText !== '') {
                $texts[] = $msgType !== '' ? ($msgType . ': ' . $msgText) : $msgText;
            }
            if ($msgType === 'error') {
                $checkOk = false;
            } elseif ($msgType === 'status' && $msgText !== '' && stripos($msgText, 'ok') === false) {
                $checkOk = false;
            }
        }
        $stRow = is_array($statusMap[$table] ?? null) ? $statusMap[$table] : [];
        $row = self::diagnoseRow($table, $stRow, $checkOk, $texts, $owned);
        $rows = $job['rows'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }
        $rows[] = $row;
        $job['rows'] = $rows;
        $job['table_idx'] = $idx + 1;
        $job['progress'] = (int)floor((($idx + 1) / max(1, $n)) * 100);
        if ($job['table_idx'] >= $n) {
            $job['status'] = 'done';
            $job['progress'] = 100;
            $job['message'] = t('core.db.job.diag_done');
            $job['result'] = ['ok' => true, 'diag' => $rows];
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $stRow SHOW TABLE STATUS row
     * @param list<string> $checkTexts
     * @param list<string> $ownedTables
     * @return array{
     *   table:string,owned:bool,check_ok:bool,check_text:string,data_length:int,index_length:int,
     *   data_free:int,rows:int,engine:string,needs_repair:bool,needs_optimize:bool,reasons:list<string>
     * }
     */
    private static function diagnoseRow(
        string $table,
        array $stRow,
        bool $checkOk,
        array $checkTexts,
        array $ownedTables
    ): array {
        $ownedSet = array_fill_keys($ownedTables, true);
        $dataLen = (int)($stRow['Data_length'] ?? 0);
        $indexLen = (int)($stRow['Index_length'] ?? 0);
        $dataFree = (int)($stRow['Data_free'] ?? 0);
        $rows = (int)($stRow['Rows'] ?? 0);
        $engine = (string)($stRow['Engine'] ?? '');
        $needsRepair = !$checkOk;
        $needsOptimize = false;
        $reasons = [];
        if ($needsRepair) {
            $reasons[] = 'repair_check';
        }
        $engineL = strtolower($engine);
        $isInno = ($engineL === 'innodb' || $engineL === '');
        if ($isInno) {
            if ($dataFree >= 64 * 1024 * 1024) {
                $needsOptimize = true;
                $reasons[] = 'optimize_free';
            } elseif ($dataLen > 0 && $dataFree >= 16 * 1024 * 1024
                && ($dataFree / $dataLen) >= 0.25) {
                $needsOptimize = true;
                $reasons[] = 'optimize_ratio';
            }
        } else {
            if ($dataFree >= 1024 * 1024) {
                $needsOptimize = true;
                $reasons[] = 'optimize_free';
            } elseif ($dataLen > 0 && ($dataFree / $dataLen) >= 0.10 && $dataFree >= 64 * 1024) {
                $needsOptimize = true;
                $reasons[] = 'optimize_ratio';
            }
        }
        return [
            'table' => $table,
            'owned' => isset($ownedSet[$table]),
            'check_ok' => $checkOk,
            'check_text' => implode('; ', $checkTexts),
            'data_length' => $dataLen,
            'index_length' => $indexLen,
            'data_free' => $dataFree,
            'rows' => $rows,
            'engine' => $engine,
            'needs_repair' => $needsRepair,
            'needs_optimize' => $needsOptimize,
            'reasons' => $reasons,
        ];
    }

    /**
     * CHECK + fragmentation hints for maintenance UI.
     *
     * @param list<string> $tables
     * @param list<string>|null $ownedTables
     * @return list<array{
     *   table:string,
     *   owned:bool,
     *   check_ok:bool,
     *   check_text:string,
     *   data_length:int,
     *   index_length:int,
     *   data_free:int,
     *   rows:int,
     *   engine:string,
     *   needs_repair:bool,
     *   needs_optimize:bool,
     *   reasons:list<string>
     * }>
     */
    public static function diagnose(array $tables, ?array $ownedTables = null): array
    {
        $tables = self::filterValidTables($tables);
        if ($tables === []) {
            return [];
        }
        $owned = $ownedTables !== null ? self::filterValidTables($ownedTables) : self::ownedTables();

        $statusMap = [];
        try {
            $st = Database::pdo()->query('SHOW TABLE STATUS');
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $name = (string)($row['Name'] ?? '');
                if ($name !== '') {
                    $statusMap[$name] = $row;
                }
            }
        } catch (Throwable $e) {
            // continue with check-only
        }

        /** @var array<string, array{ok:bool,texts:list<string>}> $checkMap */
        $checkMap = [];
        foreach (array_chunk($tables, 25) as $chunk) {
            foreach (self::check($chunk) as $r) {
                $t = (string)($r['table'] ?? '');
                if (strpos($t, '.') !== false) {
                    $t = substr($t, strrpos($t, '.') + 1);
                }
                if ($t === '' || !self::isValidTableName($t)) {
                    continue;
                }
                if (!isset($checkMap[$t])) {
                    $checkMap[$t] = ['ok' => true, 'texts' => []];
                }
                $msgType = strtolower((string)($r['Msg_type'] ?? ''));
                $msgText = (string)($r['Msg_text'] ?? '');
                if ($msgText !== '') {
                    $checkMap[$t]['texts'][] = $msgType !== '' ? ($msgType . ': ' . $msgText) : $msgText;
                }
                if ($msgType === 'error') {
                    $checkMap[$t]['ok'] = false;
                } elseif ($msgType === 'status' && $msgText !== '' && stripos($msgText, 'ok') === false) {
                    $checkMap[$t]['ok'] = false;
                }
            }
        }

        $out = [];
        foreach ($tables as $t) {
            $check = $checkMap[$t] ?? ['ok' => true, 'texts' => []];
            $stRow = is_array($statusMap[$t] ?? null) ? $statusMap[$t] : [];
            $out[] = self::diagnoseRow(
                $t,
                $stRow,
                !empty($check['ok']),
                $check['texts'] ?? [],
                $owned
            );
        }
        return $out;
    }

    /**
     * @param list<string> $tables
     * @return list<array{table:string,Msg_text?:string,Msg_type?:string,status:string}>
     */
    public static function check(array $tables): array
    {
        return self::runTableOp('CHECK', $tables);
    }

    /**
     * @param list<string> $tables
     * @return list<array{table:string,Msg_text?:string,Msg_type?:string,status:string}>
     */
    private static function runTableOp(string $op, array $tables): array
    {
        $tables = self::filterValidTables($tables);
        if ($tables === []) {
            throw new InvalidArgumentException('No tables selected');
        }
        $pdo = Database::pdo();
        $list = [];
        foreach ($tables as $t) {
            $list[] = '`' . str_replace('`', '``', $t) . '`';
        }
        $sql = $op . ' TABLE ' . implode(', ', $list);
        $st = $pdo->query($sql);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'table' => (string)($row['Table'] ?? ''),
                'Msg_type' => (string)($row['Msg_type'] ?? ''),
                'Msg_text' => (string)($row['Msg_text'] ?? ''),
                'status' => strtolower((string)($row['Msg_type'] ?? '')) === 'status'
                    && stripos((string)($row['Msg_text'] ?? ''), 'ok') !== false
                    ? 'ok'
                    : 'info',
            ];
        }
        return $out;
    }

    /**
     * @param list<string> $tables
     * @return array{ok:bool,dropped:list<string>}
     */
    public static function dropTables(array $tables): array
    {
        $tables = self::filterValidTables($tables);
        if ($tables === []) {
            throw new InvalidArgumentException('No tables to drop');
        }
        $pdo = Database::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $dropped = [];
        try {
            foreach ($tables as $t) {
                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $t) . '`');
                $dropped[] = $t;
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            Database::clearSchemaCache();
            SchemaMigrator::clearSetupStatusCache();
        }
        return ['ok' => true, 'dropped' => $dropped];
    }

    public static function modeForUser(int $userId): string
    {
        if (!class_exists('UserPrefsRepository', false)) {
            require_once base_path('modules/core/lib/UserPrefsRepository.php');
        }
        $mode = UserPrefsRepository::get($userId, self::PREF_MODE);
        return $mode === self::MODE_EXPERT ? self::MODE_EXPERT : self::MODE_NORMAL;
    }

    public static function setModeForUser(int $userId, string $mode): void
    {
        if (!class_exists('UserPrefsRepository', false)) {
            require_once base_path('modules/core/lib/UserPrefsRepository.php');
        }
        $mode = $mode === self::MODE_EXPERT ? self::MODE_EXPERT : self::MODE_NORMAL;
        UserPrefsRepository::set($userId, self::PREF_MODE, $mode);
    }

    /** @return int bytes; -1 means unlimited */
    private static function memoryLimitBytes(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '' || $limit === '-1') {
            return -1;
        }
        $unit = strtolower(substr($limit, -1));
        $num = (float)$limit;
        if ($unit === 'g') {
            return (int)($num * 1024 * 1024 * 1024);
        }
        if ($unit === 'm') {
            return (int)($num * 1024 * 1024);
        }
        if ($unit === 'k') {
            return (int)($num * 1024);
        }
        return (int)$num;
    }
}
