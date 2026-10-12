<?php
declare(strict_types=1);

/** DB hub: chunked job orchestration and persistence. */
final class DbJobs
{
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
            $tables = DbTables::filterValidTables($opts['tables'] ?? []);
            if ($tables === []) {
                throw new InvalidArgumentException('No tables to dump');
            }
            foreach ($tables as $t) {
                if (!Database::tableExists($t)) {
                    throw new RuntimeException('Table missing: ' . $t);
                }
            }
            $dir = DbFs::dumpsDir();
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
                . '-- Compression: ' . ($willGzip ? ('gzip level ' . DbDump::GZIP_LEVEL . ' (single-member finalize)') : 'none') . "\n"
                . "-- Statement end: TAB+semicolon (Sypex-compatible)\n\n"
                . 'SET NAMES utf8mb4' . DbDump::STMT_END
                . 'SET FOREIGN_KEY_CHECKS=0' . DbDump::STMT_END . "\n";
            DbDump::dumpWriteChunk($pathSql, $header, false, true);

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
            DbFetch::assertFetchableUrl($parts);
            $hostSafe = DbFetch::sanitizeHostMarker((string)$parts['host']);
            $pathPart = (string)($parts['path'] ?? '');
            $ext = '.sql.gz';
            if (preg_match('/\.sql$/i', $pathPart) && !preg_match('/\.sql\.gz$/i', $pathPart)) {
                $ext = '.sql';
            } elseif (preg_match('/\.sql\.gz$/i', $pathPart)) {
                $ext = '.sql.gz';
            }
            $basename = date('Y-m-d_H-i-s') . '-from-' . $hostSafe . $ext;
            $dest = DbFs::dumpsDir() . '/' . $basename;
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
            $path = DbFs::resolveDumpPath($file);
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
            $allowed = DbTables::filterValidTables($opts['allowed'] ?? []);
            foreach (DbTables::declaredTables() as $t) {
                $allowed[] = $t;
            }
            $sqlSample = DbRestore::readDumpHead($restorePath, 2 * 1024 * 1024);
            foreach (DbRestore::extractReferencedTables($sqlSample) as $t) {
                if (DbTables::isValidTableName($t)) {
                    $allowed[] = $t;
                }
            }
            $allowed = DbTables::filterValidTables($allowed);
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
            $metaBytes = DbFs::readDumpMetaBytes($path);
            if ($metaBytes <= 0) {
                $metaBytes = DbFs::readDumpMetaBytes($restorePath);
            }
            if ($isGz) {
                $inflated = DbFs::dumpsDir() . '/.restore_' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$job['id']) . '.sql';
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
            $tables = DbTables::filterValidTables($opts['tables'] ?? []);
            if ($tables === []) {
                throw new InvalidArgumentException('No tables to diagnose');
            }
            $owned = DbTables::filterValidTables($opts['owned'] ?? DbTables::ownedTables());
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
            $tables = DbTables::filterValidTables($opts['tables'] ?? []);
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
                $job = DbDump::tickDump($job);
            } elseif ($op === 'restore') {
                $job = DbRestore::tickRestore($job);
            } elseif ($op === 'diagnose') {
                $job = DbMaint::tickDiagnose($job);
            } elseif ($op === 'fetch') {
                $job = DbFetch::tickFetch($job);
            } elseif (in_array($op, ['optimize', 'repair', 'check'], true)) {
                $job = DbMaint::tickMaint($job);
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
                $cand = DbFs::dumpsDir() . '/' . basename($file);
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

    public static function jobBufferPath(string $id): string
    {
        $id = basename($id);
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            return '';
        }
        return DbFs::jobsDir() . '/' . $id . '.buf';
    }

    /** @param array<string,mixed> $job */
    public static function jobBufferRead(array $job): string
    {
        $path = (string)($job['buffer_file'] ?? self::jobBufferPath((string)($job['id'] ?? '')));
        if ($path === '' || !is_file($path)) {
            return '';
        }
        $raw = file_get_contents($path);
        return is_string($raw) ? $raw : '';
    }

    /** @param array<string,mixed> $job */
    public static function jobBufferWrite(array $job, string $buffer): void
    {
        $path = (string)($job['buffer_file'] ?? self::jobBufferPath((string)($job['id'] ?? '')));
        if ($path === '') {
            return;
        }
        if ($buffer === '') {
            @unlink($path);
            return;
        }
        if (strlen($buffer) > DbRestore::RESTORE_BUFFER_MAX) {
            throw new RuntimeException(
                'Restore statement buffer exceeds ' . (int)(DbRestore::RESTORE_BUFFER_MAX / 1024 / 1024) . ' MB'
            );
        }
        if (file_put_contents($path, $buffer, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write restore buffer');
        }
    }

    private static function jobPath(string $id): string
    {
        $id = basename($id);
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new InvalidArgumentException('Invalid job id');
        }
        return DbFs::jobsDir() . '/' . $id . '.json';
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
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function attachProgressMeta(array $job, float $overallFrac, float $tableFrac): array
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

}
