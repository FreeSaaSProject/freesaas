<?php
declare(strict_types=1);

/** DB hub: dump restore (inflate + SQL) and statement split. */
final class DbRestore
{
    /** SQL statements soft cap per restore tick (also stopped by time budget). */
    public const RESTORE_STMT_CHUNK = 8000;
    /** Soft wall-clock budget per restore tick (seconds). */
    public const RESTORE_TICK_SECONDS = 240.0;
    /** Soft wall-clock for one-shot gunzip before SQL restore. */
    public const RESTORE_INFLATE_TICK_SECONDS = 120.0;
    /** Max carry-over SQL buffer on disk (bytes) — protects against runaway statements. */
    public const RESTORE_BUFFER_MAX = 64 * 1024 * 1024;

    /**
     * @param list<string> $allowedTables
     * @return array{ok:bool,statements:int,tables:list<string>}
     */
    public static function restore(string $file, array $allowedTables): array
    {
        @set_time_limit(0);
        $job = DbJobs::jobStart('restore', [
            'file' => $file,
            'allowed' => DbTables::filterValidTables($allowedTables),
        ]);
        $id = (string)$job['id'];
        while (($job['status'] ?? '') === 'running') {
            $job = DbJobs::jobTick($id);
        }
        if (($job['status'] ?? '') !== 'done') {
            throw new RuntimeException((string)($job['error'] ?? 'Restore failed'));
        }
        /** @var array{ok:bool,statements:int,tables:list<string>} $result */
        $result = $job['result'] ?? [];
        return $result;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function tickRestore(array $job): array
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
            'bytes' => DbFs::formatBytesLabel($written),
        ]);
        $job = DbJobs::attachProgressMeta($job, $frac, min(0.95, $written / max(1, $est)));
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
        return DbJobs::attachProgressMeta($job, 0.2, 0.0);
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function tickRestoreSql(array $job): array
    {
        @set_time_limit(600);
        $path = (string)($job['path'] ?? '');
        $allowed = DbTables::filterValidTables($job['allowed'] ?? []);
        // Reconnect after laptop sleep / mysqld restart (SQLSTATE 2002).
        $pdo = Database::ensureConnected();
        self::restorePrepareSession($pdo);
        $job['fk_off'] = true;

        if (empty($job['buffer_file'])) {
            $job['buffer_file'] = DbJobs::jobBufferPath((string)($job['id'] ?? ''));
        }

        // After inflate, path is plain .sql (gzip=false). Plain fseek is instant.
        $isGz = !empty($job['gzip']);
        $buffer = DbJobs::jobBufferRead($job);
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
        DbJobs::jobBufferWrite($job, $buffer);
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
        $job = DbJobs::attachProgressMeta($job, $frac, $tableFrac);
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
            DbJobs::jobBufferWrite($job, '');
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
            return DbJobs::attachProgressMeta($job, 1.0, 1.0);
        }
        return $job;
    }

    /** @param array<string,bool> $allow */
    private static function restoreTableAllowed(string $tname, array $allow, bool $allowAnyValid): bool
    {
        if (!DbTables::isValidTableName($tname)) {
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
                    if (DbTables::isValidTableName($name)) {
                        $found[$name] = true;
                    }
                }
            }
        }
        $out = array_keys($found);
        sort($out, SORT_STRING);
        return $out;
    }

    public static function readDumpHead(string $path, int $maxBytes): string
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

}
