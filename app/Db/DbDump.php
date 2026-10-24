<?php
declare(strict_types=1);

/** DB hub: SQL dump generation and gzip finalize. */
final class DbDump
{
    /** Max rows per INSERT (secondary cap; size target usually hits first). */
    public const INSERT_ROWS = 8000;
    /** Target uncompressed size per INSERT (under typical max_allowed_packet). */
    public const INSERT_BYTES = 1048576; // 1 MiB
    /** Rows cap per dump tick (Sypex uses 25000; we allow more within wall-clock budget). */
    public const ROW_CHUNK = 50000;
    /** Soft wall-clock budget per dump tick (seconds). Sypex time_web defaults to 600. */
    public const DUMP_TICK_SECONDS = 60.0;
    /** Flush gzip/plain write buffer at this size. */
    public const DUMP_WRITE_BUFFER = 61440; // Sypex flush threshold
    /** Gzip level for finalize (1=fastest/largest … 9=slowest/smallest). */
    public const GZIP_LEVEL = 5;
    /** Sypex statement terminator — safe inside serialized strings; enables fast split. */
    public const STMT_END = "\t;\n";

    /**
     * @param list<string> $tables
     * @return array{ok:bool,file:string,path:string,bytes:int,tables:list<string>,gzip:bool}
     */
    public static function dump(array $tables, ?string $label = null): array
    {
        @set_time_limit(0);
        $job = DbJobs::jobStart('dump', [
            'tables' => DbTables::filterValidTables($tables),
            'label' => $label,
        ]);
        $id = (string)$job['id'];
        while (($job['status'] ?? '') === 'running') {
            $job = DbJobs::jobTick($id);
        }
        if (($job['status'] ?? '') !== 'done') {
            throw new RuntimeException((string)($job['error'] ?? 'Dump failed'));
        }
        /** @var array{ok:bool,file:string,path:string,bytes:int,tables:list<string>,gzip:bool} $result */
        $result = $job['result'] ?? [];
        return $result;
    }

    /**
     * Dump tables exclusively owned by one module (module.json → tables not claimed elsewhere).
     *
     * @return array{ok:bool,file?:string,path?:string,bytes?:int,tables:list<string>,gzip?:bool,skipped?:bool,skipped_shared?:array<string,list<string>>}
     */
    public static function dumpModule(string $moduleId, ?string $label = null): array
    {
        $moduleId = trim($moduleId);
        $meta = ModuleManager::get($moduleId);
        if ($meta === null) {
            throw new RuntimeException(t('app.module.err.not_found', ['id' => $moduleId]));
        }
        $ownership = ModuleManager::exclusiveOwnedTables($moduleId);
        $existing = [];
        foreach ($ownership['exclusive'] as $t) {
            if (DbTables::isValidTableName($t) && Database::tableExists($t)) {
                $existing[] = $t;
            }
        }
        if ($existing === []) {
            return [
                'ok' => true,
                'tables' => [],
                'skipped' => true,
                'skipped_shared' => $ownership['shared'],
            ];
        }
        if ($label !== null && trim($label) !== '') {
            $lab = trim($label);
        } else {
            // e.g. uninstall-stickers-3t — readable in var/db_dumps/ listing
            $lab = 'uninstall-' . $moduleId . '-' . count($existing) . 't';
        }
        $out = self::dump($existing, $lab);
        $out['skipped'] = false;
        $out['skipped_shared'] = $ownership['shared'];
        return $out;
    }

    public static function dumpWriteChunk(string $path, string $chunk, bool $gzip, bool $create): void
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
    public static function tickDump(array $job): array
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
                $job = DbJobs::attachProgressMeta($job, 0.99, 1.0);
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
                    return DbJobs::attachProgressMeta($job, 0.99, 1.0);
                }
                $bytes = is_file($path) ? (int)filesize($path) : 0;
                $job['status'] = 'done';
                $job['phase'] = 'done';
                $job['file'] = basename($path);
                $job['message'] = t('core.db.job.dump_done', [
                    'file' => (string)$job['file'],
                    'bytes' => DbFs::formatBytesLabel($bytes),
                    'raw' => DbFs::formatBytesLabel((int)($job['bytes_raw'] ?? $bytes)),
                    'level' => '0',
                    'elapsed' => DbFs::formatDurationLabel((int)(time() - (int)($job['created_at'] ?? time()))),
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
                return DbJobs::attachProgressMeta($job, 1.0, 1.0);
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
                return DbJobs::attachProgressMeta($job, $overall, 0.0);
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
            return DbJobs::attachProgressMeta($job, $overall, $tableFrac);
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
        $job = DbJobs::attachProgressMeta($job, 0.99, 1.0);
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
        DbFs::writeDumpMeta($gzPath, $sqlBytes);
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
            'bytes' => DbFs::formatBytesLabel($bytes),
            'elapsed' => DbFs::formatDurationLabel((int)(time() - (int)($job['created_at'] ?? time()))),
            'level' => (string)self::GZIP_LEVEL,
            'raw' => DbFs::formatBytesLabel($sqlBytes),
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
        return DbJobs::attachProgressMeta($job, 1.0, 1.0);
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

}
