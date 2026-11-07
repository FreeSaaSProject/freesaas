<?php
declare(strict_types=1);

/**
 * Restore / greenfield deploy from a single pack (.tar / .tar.enc) or legacy stamp dir.
 * Refuses when the connect database already contains any rows.
 */
final class BackupRestore
{
    public const MAX_DOWNLOAD_BYTES = 10737418240; // 10 GiB

    /**
     * True when every table has zero rows (or no tables). Any data → false.
     */
    public static function databaseIsEmpty(): bool
    {
        try {
            $pdo = Database::pdo();
            $st = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
            if ($st === false) {
                return false;
            }
            while ($row = $st->fetch(PDO::FETCH_NUM)) {
                $name = (string)($row[0] ?? '');
                if ($name === '') {
                    continue;
                }
                // Quote safely — only allow simple identifiers
                if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                    return false;
                }
                $cnt = $pdo->query('SELECT COUNT(*) FROM `' . $name . '`')->fetchColumn();
                if ((int)$cnt > 0) {
                    return false;
                }
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function assertEmptyDatabase(): void
    {
        if (!self::databaseIsEmpty()) {
            throw new RuntimeException(t('backups.err.db_not_empty'));
        }
    }

    /**
     * Non-base_* tables that contain rows (shared-DB guard before DROP base_*).
     *
     * @return list<string>
     */
    public static function foreignTablesWithRows(): array
    {
        $pdo = Database::pdo();
        $out = [];
        $st = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
        while ($st && ($row = $st->fetch(PDO::FETCH_NUM))) {
            $name = (string)($row[0] ?? '');
            if ($name === '' || preg_match('/^base_/', $name)) {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                $out[] = $name;
                continue;
            }
            $cnt = (int)$pdo->query('SELECT COUNT(*) FROM `' . $name . '`')->fetchColumn();
            if ($cnt > 0) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Structural gate: gzip magic (when .gz) + a real CREATE TABLE `base_*` statement
     * outside string literals. Rejects comment-only and SELECT 'CREATE TABLE …' decoys.
     * Prefer preflightDumpBeforeDrop() before any DROP.
     */
    public static function dumpLooksValid(string $path): bool
    {
        return self::extractFirstBaseCreateTable(self::readDumpSample($path)) !== null;
    }

    /**
     * Pre-DROP proof:
     * 1) stream the whole dump — every statement must terminate (truncated INSERT fails);
     * 2) take exactly one CREATE TABLE `base_*` (quote-aware `;`, never TAB-only chunks);
     * 3) execute only that rewritten DDL on a throwaway staging table, then DROP it.
     * Does not touch live base_* tables. Never runs SET/INSERT/other dump ops.
     *
     * @return array{ok:bool,error:string,table?:string,staging?:string}
     */
    public static function preflightDumpBeforeDrop(string $path): array
    {
        if ($path === '' || !is_file($path) || filesize($path) < 10) {
            return ['ok' => false, 'error' => 'dump missing or empty'];
        }
        if (preg_match('/\.gz$/i', $path)) {
            $fh = fopen($path, 'rb');
            if ($fh === false) {
                return ['ok' => false, 'error' => 'cannot open dump'];
            }
            $magic = fread($fh, 2);
            fclose($fh);
            if ($magic !== "\x1f\x8b") {
                return ['ok' => false, 'error' => 'not gzip'];
            }
        }

        $complete = self::assertDumpStatementsComplete($path);
        if (empty($complete['ok'])) {
            return ['ok' => false, 'error' => (string)($complete['error'] ?? 'dump incomplete')];
        }

        $found = self::extractFirstBaseCreateTable(self::readDumpSample($path, 524288));
        if ($found === null) {
            return ['ok' => false, 'error' => 'no CREATE TABLE base_* statement outside string literals'];
        }
        $table = $found['table'];
        // extractFirst already used quote-aware `;` — one statement, no glued SET/INSERT.
        $ddl = ltrim($found['sql']);
        if (!preg_match(
            '/^CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`([^`]+)`|([A-Za-z0-9_]+))/i',
            $ddl,
            $m
        )) {
            return ['ok' => false, 'error' => 'CREATE TABLE parse failed'];
        }
        $ddlTable = (string)(!empty($m[1]) ? $m[1] : ($m[2] ?? ''));
        if ($ddlTable !== $table) {
            return ['ok' => false, 'error' => 'CREATE TABLE name mismatch'];
        }
        // Refuse multi-query payload (PDO may execute more than one statement).
        if (self::sqlHasStatementTerminatorOutsideStrings($ddl)) {
            return ['ok' => false, 'error' => 'CREATE DDL contains an embedded statement terminator'];
        }

        $staging = '__fs_pf_' . substr(bin2hex(random_bytes(4)), 0, 8) . '_' . $table;
        if (strlen($staging) > 64) {
            $staging = substr($staging, 0, 64);
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $staging)) {
            return ['ok' => false, 'error' => 'bad staging name'];
        }
        $rewritten = preg_replace(
            '/^(CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)(?:`[^`]+`|[A-Za-z0-9_]+)/i',
            '$1`' . $staging . '`',
            $ddl,
            1,
            $replacements
        );
        if (!is_string($rewritten) || $replacements < 1) {
            return ['ok' => false, 'error' => 'cannot rewrite CREATE TABLE for staging'];
        }
        if (self::sqlHasStatementTerminatorOutsideStrings($rewritten)) {
            return ['ok' => false, 'error' => 'rewritten DDL is multi-statement'];
        }

        $pdo = Database::pdo();
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $pdo->exec('DROP TABLE IF EXISTS `' . $staging . '`');
            // Single DDL only — never the dump tail / SET / INSERT.
            $pdo->exec($rewritten);
            $exists = Database::tableExists($staging);
            if (!$exists) {
                return ['ok' => false, 'error' => 'staging table missing after CREATE', 'table' => $table, 'staging' => $staging];
            }
            return ['ok' => true, 'error' => '', 'table' => $table, 'staging' => $staging];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => 'staging CREATE failed: ' . $e->getMessage(),
                'table' => $table,
                'staging' => $staging,
            ];
        } finally {
            try {
                $pdo->exec('DROP TABLE IF EXISTS `' . $staging . '`');
            } catch (Throwable $e) {
                // ignore cleanup
            }
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * Stream the whole dump: every SQL statement must end with `;` (quote-aware).
     * Truncated INSERT / open string at EOF → fail. Does not execute SQL.
     *
     * @return array{ok:bool,error:string,statements?:int}
     */
    public static function assertDumpStatementsComplete(string $path): array
    {
        if (preg_match('/\.gz$/i', $path)) {
            $fh = @gzopen($path, 'rb');
            if ($fh === false) {
                return ['ok' => false, 'error' => 'cannot gunzip dump'];
            }
            $read = static function () use ($fh) {
                if (gzeof($fh)) {
                    return false;
                }
                $c = gzread($fh, 65536);
                return ($c === false || $c === '') ? false : $c;
            };
            $close = static function () use ($fh): void {
                gzclose($fh);
            };
        } else {
            $fh = fopen($path, 'rb');
            if ($fh === false) {
                return ['ok' => false, 'error' => 'cannot open dump'];
            }
            $read = static function () use ($fh) {
                if (feof($fh)) {
                    return false;
                }
                $c = fread($fh, 65536);
                return ($c === false || $c === '') ? false : $c;
            };
            $close = static function () use ($fh): void {
                fclose($fh);
            };
        }

        $scan = self::sqlScanStateNew();
        $statements = 0;
        try {
            while (($chunk = $read()) !== false) {
                if ($statements === 0 && $scan['buf'] === '' && strncmp($chunk, "\xEF\xBB\xBF", 3) === 0) {
                    $chunk = substr($chunk, 3);
                }
                $got = self::sqlScanFeed($scan, $chunk);
                $statements += count($got);
            }
            if (!self::sqlScanLeftoverIsIgnorable($scan)) {
                $hint = self::sqlScanLeftoverHint($scan);
                return [
                    'ok' => false,
                    'error' => 'dump ends with an incomplete SQL statement' . ($hint !== '' ? ' (' . $hint . ')' : ''),
                    'statements' => $statements,
                ];
            }
            if ($statements < 1) {
                return ['ok' => false, 'error' => 'dump has no SQL statements', 'statements' => 0];
            }
            return ['ok' => true, 'error' => '', 'statements' => $statements];
        } finally {
            $close();
        }
    }

    /** @return string inflated dump head (empty on failure) */
    private static function readDumpSample(string $path, int $maxBytes = 262144): string
    {
        if ($path === '' || !is_file($path) || filesize($path) < 10) {
            return '';
        }
        $maxBytes = max(4096, min(1048576, $maxBytes));
        if (preg_match('/\.gz$/i', $path)) {
            $fh = fopen($path, 'rb');
            if ($fh === false) {
                return '';
            }
            $magic = fread($fh, 2);
            fclose($fh);
            if ($magic !== "\x1f\x8b") {
                return '';
            }
            $gz = @gzopen($path, 'rb');
            if ($gz === false) {
                return '';
            }
            $sample = (string)gzread($gz, $maxBytes);
            gzclose($gz);
        } else {
            $raw = file_get_contents($path, false, null, 0, $maxBytes);
            $sample = is_string($raw) ? $raw : '';
        }
        if ($sample === '') {
            return '';
        }
        if (strncmp($sample, "\xEF\xBB\xBF", 3) === 0) {
            $sample = substr($sample, 3);
        }
        return $sample;
    }

    /**
     * First CREATE TABLE for a base_* name as a real SQL statement (quote/comment aware `;`).
     * Never uses TAB+`;` alone — that can glue CREATE + SET into one chunk.
     *
     * @return array{sql:string,table:string}|null
     */
    private static function extractFirstBaseCreateTable(string $sql): ?array
    {
        if ($sql === '') {
            return null;
        }
        foreach (self::splitSqlStatementsForPreflight($sql) as $stmt) {
            $trim = ltrim($stmt);
            if ($trim === '' || strpos($trim, '--') === 0 || strpos($trim, '#') === 0) {
                continue;
            }
            if (!preg_match(
                '/^CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`([^`]+)`|([A-Za-z0-9_]+))/i',
                $trim,
                $m
            )) {
                continue;
            }
            $table = (string)(!empty($m[1]) ? $m[1] : ($m[2] ?? ''));
            if ($table === '' || !preg_match('/^base_[A-Za-z0-9_]+$/', $table)) {
                continue;
            }
            return ['sql' => rtrim($trim, " \t\n\r;"), 'table' => $table];
        }
        return null;
    }

    /**
     * Quote/comment-aware `;` split only (FreeSaaS `\t;` still ends on `;`).
     *
     * @return list<string>
     */
    private static function splitSqlStatementsForPreflight(string $sql): array
    {
        $scan = self::sqlScanStateNew();
        $stmts = self::sqlScanFeed($scan, str_replace(["\r\n", "\r"], "\n", $sql));
        // Incomplete tail is kept in state; callers that need completeness use assertDumpStatementsComplete.
        if (self::sqlScanLeftoverIsIgnorable($scan)) {
            return $stmts;
        }
        // For extract-from-sample: ignore truncated last fragment (sample may end mid-file).
        return $stmts;
    }

    /** True when `;` appears outside quotes/comments (multi-statement risk for PDO::exec). */
    private static function sqlHasStatementTerminatorOutsideStrings(string $sql): bool
    {
        $scan = self::sqlScanStateNew();
        $parts = self::sqlScanFeed($scan, $sql);
        // A terminator inside the string produces ≥1 completed statement while feeding.
        // Single DDL with no `;` → 0 parts, leftover = full DDL.
        if (count($parts) >= 1) {
            return true;
        }
        return false;
    }

    /**
     * @return array{buf:string,in_single:bool,in_double:bool,in_backtick:bool,in_line:bool,in_block:bool}
     */
    private static function sqlScanStateNew(): array
    {
        return [
            'buf' => '',
            'in_single' => false,
            'in_double' => false,
            'in_backtick' => false,
            'in_line' => false,
            'in_block' => false,
        ];
    }

    /**
     * @param array{buf:string,in_single:bool,in_double:bool,in_backtick:bool,in_line:bool,in_block:bool} $scan
     * @return list<string> newly completed statements
     */
    private static function sqlScanFeed(array &$scan, string $sql): array
    {
        $stmts = [];
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($scan['in_line']) {
                if ($ch === "\n") {
                    $scan['in_line'] = false;
                    $scan['buf'] .= $ch;
                }
                continue;
            }
            if ($scan['in_block']) {
                if ($ch === '*' && $next === '/') {
                    $scan['in_block'] = false;
                    $i++;
                }
                continue;
            }

            if (!$scan['in_single'] && !$scan['in_double'] && !$scan['in_backtick']) {
                if ($ch === '-' && $next === '-') {
                    $scan['in_line'] = true;
                    $i++;
                    continue;
                }
                if ($ch === '#') {
                    $scan['in_line'] = true;
                    continue;
                }
                if ($ch === '/' && $next === '*') {
                    $scan['in_block'] = true;
                    $i++;
                    continue;
                }
                if ($ch === ';') {
                    if (trim($scan['buf']) !== '') {
                        $stmts[] = $scan['buf'];
                    }
                    $scan['buf'] = '';
                    continue;
                }
            }

            if ($scan['in_single']) {
                $scan['buf'] .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $scan['buf'] .= $next;
                    $i++;
                    continue;
                }
                if ($ch === "'" && $next === "'") {
                    $scan['buf'] .= $next;
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    $scan['in_single'] = false;
                }
                continue;
            }
            if ($scan['in_double']) {
                $scan['buf'] .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $scan['buf'] .= $next;
                    $i++;
                    continue;
                }
                if ($ch === '"' && $next === '"') {
                    $scan['buf'] .= $next;
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $scan['in_double'] = false;
                }
                continue;
            }
            if ($scan['in_backtick']) {
                $scan['buf'] .= $ch;
                if ($ch === '`' && $next === '`') {
                    $scan['buf'] .= $next;
                    $i++;
                    continue;
                }
                if ($ch === '`') {
                    $scan['in_backtick'] = false;
                }
                continue;
            }

            if ($ch === "'") {
                $scan['in_single'] = true;
                $scan['buf'] .= $ch;
                continue;
            }
            if ($ch === '"') {
                $scan['in_double'] = true;
                $scan['buf'] .= $ch;
                continue;
            }
            if ($ch === '`') {
                $scan['in_backtick'] = true;
                $scan['buf'] .= $ch;
                continue;
            }
            $scan['buf'] .= $ch;
        }
        return $stmts;
    }

    /**
     * @param array{buf:string,in_single:bool,in_double:bool,in_backtick:bool,in_line:bool,in_block:bool} $scan
     */
    private static function sqlScanLeftoverIsIgnorable(array $scan): bool
    {
        if ($scan['in_single'] || $scan['in_double'] || $scan['in_backtick'] || $scan['in_block']) {
            return false;
        }
        // Open line comment to EOF is fine (no statement body).
        $left = $scan['buf'];
        if ($scan['in_line']) {
            return true;
        }
        $trim = trim($left);
        if ($trim === '') {
            return true;
        }
        // Only full-line SQL comments left.
        foreach (preg_split('/\n/', $left) ?: [] as $line) {
            $l = ltrim($line);
            if ($l === '') {
                continue;
            }
            if (strpos($l, '--') === 0 || strpos($l, '#') === 0) {
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * @param array{buf:string,in_single:bool,in_double:bool,in_backtick:bool,in_line:bool,in_block:bool} $scan
     */
    private static function sqlScanLeftoverHint(array $scan): string
    {
        if ($scan['in_single'] || $scan['in_double']) {
            return 'unclosed string';
        }
        if ($scan['in_backtick']) {
            return 'unclosed identifier';
        }
        if ($scan['in_block']) {
            return 'unclosed block comment';
        }
        $head = ltrim($scan['buf']);
        if ($head === '') {
            return '';
        }
        if (preg_match('/^(INSERT|REPLACE|UPDATE|DELETE|CREATE|ALTER|DROP|SET)\b/i', $head, $m)) {
            return 'truncated ' . strtoupper($m[1]);
        }
        return 'truncated statement';
    }

    /** @return array{empty:bool,available:bool} */
    public static function availability(): array
    {
        $empty = self::databaseIsEmpty();
        return ['empty' => $empty, 'available' => $empty];
    }

    /**
     * Download pack from https URL into var/backups/.
     *
     * @return array{ok:bool,path:string,bytes:int,error:string}
     */
    public static function fetchUrl(string $url, ?string $destName = null): array
    {
        $url = trim($url);
        $dir = BackupConfig::workDir() . '/incoming';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if ($destName === null || $destName === '') {
            $pathPart = (string)(parse_url($url, PHP_URL_PATH) ?? '');
            $base = basename($pathPart);
            if ($base === '' || $base === '/' || $base === '.') {
                $base = 'backup-' . date('Y-m-d_H-i-s') . '.tar';
            }
            $destName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $base) ?? 'backup.tar';
        }
        $dest = $dir . '/' . $destName;
        $r = SafeHttp::downloadToFile($url, $dest, self::MAX_DOWNLOAD_BYTES, 600);
        if (empty($r['ok'])) {
            return [
                'ok' => false,
                'path' => '',
                'bytes' => 0,
                'error' => (string)($r['error'] ?? 'download failed'),
            ];
        }
        return [
            'ok' => true,
            'path' => $dest,
            'bytes' => (int)($r['bytes'] ?? filesize($dest)),
            'error' => '',
        ];
    }

    /**
     * Download a remote FTP pack (or legacy dir) into a local work dir.
     *
     * @return array{ok:bool,local_dir:string,pack_path:string,kind:string,manifest:?array}
     */
    public static function downloadRemote(string $remotePath, int $budgetSec = 300, ?BackupFtpTarget $source = null): array
    {
        $ownTarget = $source === null;
        $target = $source ?? BackupFtpTarget::fromConfig();
        try {
            $stamp = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($remotePath)) ?? 'set';
            $localDir = BackupConfig::workDir() . '/restore-' . $stamp;
            if (!is_dir($localDir)) {
                @mkdir($localDir, 0755, true);
            }

            // Single pack file (budgeted resume — large lights must not hold one HTTP request)
            if (preg_match('/\.tar(\.enc)?$/i', $remotePath)) {
                $localPack = $localDir . '/' . basename($remotePath);
                $expect = $target->size($remotePath);
                $done = is_file($localPack) && $expect >= 0 && filesize($localPack) === $expect;
                $bytes = 0;
                $localSize = is_file($localPack) ? (int)filesize($localPack) : 0;
                if (!$done) {
                    $r = $target->getBudgeted($remotePath, $localPack, max(5, $budgetSec));
                    $done = !empty($r['done']);
                    $bytes = (int)($r['bytes'] ?? 0);
                    $localSize = (int)($r['local_size'] ?? $localSize);
                    if ($expect < 0) {
                        $expect = (int)($r['remote_size'] ?? -1);
                    }
                }
                return [
                    'ok' => $done && is_file($localPack),
                    'done' => $done,
                    'local_dir' => $localDir,
                    'pack_path' => $localPack,
                    'kind' => 'pack',
                    'manifest' => null,
                    'bytes' => $bytes,
                    'local_size' => $localSize,
                    'remote_size' => $expect,
                ];
            }

            // Legacy directory set
            $files = $target->listFiles($remotePath);
            $t0 = microtime(true);
            foreach ($files as $name) {
                if ((microtime(true) - $t0) > $budgetSec) {
                    break;
                }
                $dest = $localDir . '/' . $name;
                if (is_file($dest) && filesize($dest) === $target->size($remotePath . '/' . $name)) {
                    continue;
                }
                $target->get($remotePath . '/' . $name, $dest);
            }
            $manifest = self::readManifest($localDir . '/manifest.json');
            return [
                'ok' => is_file($localDir . '/manifest.json'),
                'local_dir' => $localDir,
                'pack_path' => '',
                'kind' => 'dir',
                'manifest' => $manifest,
            ];
        } finally {
            if ($ownTarget) {
                $target->close();
            }
        }
    }

    /**
     * Unpack a local .tar / .tar.enc (or already-extracted dir) into local_dir.
     *
     * @return array{ok:bool,local_dir:string,manifest:?array,decrypted:bool}
     */
    public static function prepareLocal(string $packOrDir, string $passphrase = ''): array
    {
        if (is_dir($packOrDir)) {
            return [
                'ok' => is_file($packOrDir . '/manifest.json') || is_file($packOrDir . '/db.sql.gz'),
                'local_dir' => $packOrDir,
                'manifest' => self::readManifest($packOrDir . '/manifest.json'),
                'decrypted' => false,
            ];
        }
        if (!is_file($packOrDir)) {
            throw new RuntimeException(t('backups.err.restore_pack_missing'));
        }

        $localDir = dirname($packOrDir) . '/_unpacked_' . preg_replace('/[^a-zA-Z0-9_-]/', '', basename($packOrDir));
        if (!is_dir($localDir)) {
            @mkdir($localDir, 0755, true);
        }

        $tarPath = $packOrDir;
        $decrypted = false;
        if (preg_match('/\.enc$/i', $packOrDir)) {
            if ($passphrase === '') {
                throw new RuntimeException(t('backups.err.passphrase'));
            }
            $tarPath = preg_replace('/\.enc$/i', '', $packOrDir);
            if (!is_string($tarPath) || $tarPath === $packOrDir) {
                $tarPath = $packOrDir . '.decrypted.tar';
            }
            if (!is_file($tarPath)) {
                BackupCrypto::decryptFile($packOrDir, $tarPath, $passphrase);
            }
            $decrypted = true;
        }

        self::extractTar($tarPath, $localDir);
        return [
            'ok' => is_file($localDir . '/manifest.json') || is_file($localDir . '/db.sql.gz'),
            'local_dir' => $localDir,
            'manifest' => self::readManifest($localDir . '/manifest.json'),
            'decrypted' => $decrypted,
        ];
    }

    /**
     * Full deploy into empty DB: prepare → db restore (tick until done) → files/cfg.
     *
     * db_only=true restores only the database (skips cfg.tar merge and file trees).
     *
     * @param array{url?:string,file?:string,remote_path?:string,passphrase?:string,migrate?:bool,db_only?:bool} $opts
     * @return array<string,mixed>
     */
    public static function deploy(array $opts): array
    {
        self::assertEmptyDatabase();
        $passphrase = (string)($opts['passphrase'] ?? '');
        $steps = [];
        $localPack = '';
        $localDir = '';

        if (!empty($opts['url'])) {
            $dl = self::fetchUrl((string)$opts['url']);
            if (empty($dl['ok'])) {
                return ['ok' => false, 'error' => $dl['error'], 'http' => 400, 'steps' => $steps];
            }
            $localPack = $dl['path'];
            $steps[] = ['step' => 'download_url', 'status' => 'ok', 'bytes' => $dl['bytes']];
        } elseif (!empty($opts['remote_path'])) {
            $dl = self::downloadRemote((string)$opts['remote_path']);
            if (empty($dl['ok'])) {
                return ['ok' => false, 'error' => 'remote download failed', 'http' => 400, 'steps' => $steps];
            }
            $localPack = (string)$dl['pack_path'];
            $localDir = (string)$dl['local_dir'];
            $steps[] = ['step' => 'download_ftp', 'status' => 'ok', 'kind' => $dl['kind']];
            if ($localPack === '' && $localDir !== '') {
                // legacy dir already extracted
                $prep = ['ok' => true, 'local_dir' => $localDir, 'manifest' => $dl['manifest'], 'decrypted' => false];
            }
        } elseif (!empty($opts['file'])) {
            $localPack = (string)$opts['file'];
            if (!is_file($localPack) && !is_dir($localPack)) {
                return ['ok' => false, 'error' => t('backups.err.restore_pack_missing'), 'http' => 400, 'steps' => $steps];
            }
            $steps[] = ['step' => 'local_file', 'status' => 'ok'];
        } else {
            return ['ok' => false, 'error' => 'url, file, or remote_path required', 'http' => 400, 'steps' => $steps];
        }

        if (!isset($prep)) {
            $src = $localPack !== '' ? $localPack : $localDir;
            $prep = self::prepareLocal($src, $passphrase);
            $steps[] = [
                'step' => 'prepare',
                'status' => !empty($prep['ok']) ? 'ok' : 'error',
                'decrypted' => !empty($prep['decrypted']),
                'local_dir' => $prep['local_dir'],
            ];
            if (empty($prep['ok'])) {
                return ['ok' => false, 'error' => 'prepare failed', 'http' => 400, 'steps' => $steps];
            }
        }
        $localDir = (string)$prep['local_dir'];

        // Re-check emptiness before destructive SQL
        self::assertEmptyDatabase();

        $jobId = self::startDbRestore($localDir);
        $job = null;
        for ($i = 0; $i < 500; $i++) {
            $job = DbJobs::jobTick($jobId);
            if (($job['status'] ?? '') !== 'running') {
                break;
            }
        }
        $steps[] = [
            'step' => 'db_restore',
            'status' => (string)($job['status'] ?? ''),
            'job_id' => $jobId,
            'error' => (string)($job['error'] ?? ''),
        ];
        if (($job['status'] ?? '') !== 'done') {
            return [
                'ok' => false,
                'error' => (string)($job['error'] ?? 'db restore failed'),
                'http' => 500,
                'steps' => $steps,
                'job' => $job,
            ];
        }

        if (!empty($opts['db_only'])) {
            $steps[] = ['step' => 'files', 'status' => 'skipped', 'reason' => 'db_only'];
        } else {
            $files = self::applyFiles($localDir);
            $steps[] = ['step' => 'files', 'status' => 'ok', 'applied' => $files['applied'] ?? []];
        }

        $wantMigrate = !array_key_exists('migrate', $opts) || !empty($opts['migrate']);
        if ($wantMigrate) {
            try {
                $mig = AgentOps::migrateRun(null);
                $steps[] = ['step' => 'migrate', 'status' => !empty($mig['ok']) ? 'ok' : 'error', 'result' => $mig];
            } catch (Throwable $e) {
                $steps[] = ['step' => 'migrate', 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        return [
            'ok' => true,
            'http' => 200,
            'local_dir' => $localDir,
            'manifest' => $prep['manifest'] ?? null,
            'steps' => $steps,
            'hint' => 'Run php bin/smoke.php; log in with a user from the restored dump.',
        ];
    }

    /**
     * Start DB restore from local db.sql.gz (or decrypted). Returns DbJobs id.
     *
     * @param float $tickSeconds Soft wall per DbJobs restore crumb (0 = default ~240s).
     */
    public static function startDbRestore(string $localDir, float $tickSeconds = 0.0): string
    {
        $candidates = [$localDir . '/db.sql.gz', $localDir . '/db.sql'];
        $file = '';
        foreach ($candidates as $c) {
            if (is_file($c)) {
                $file = $c;
                break;
            }
        }
        if ($file === '') {
            throw new RuntimeException(t('backups.err.restore_no_db'));
        }
        $basename = basename($file);
        $dest = DbFs::dumpsDir() . '/' . $basename;
        if (@realpath($file) !== @realpath($dest)) {
            if (!@copy($file, $dest)) {
                throw new RuntimeException('Cannot stage dump for restore');
            }
        }
        $opts = ['file' => $basename];
        if ($tickSeconds > 0) {
            $opts['tick_seconds'] = $tickSeconds;
        }
        $job = DbJobs::jobStart('restore', $opts);
        return (string)$job['id'];
    }

    /** Extract cfg.tar and file tars; merge cfg preserving local connect/url. */
    public static function applyFiles(string $localDir): array
    {
        $stage = $localDir . '/_extract';
        if (!is_dir($stage)) {
            @mkdir($stage, 0755, true);
        }
        $applied = [];
        foreach (glob($localDir . '/*.tar') ?: [] as $tar) {
            $name = basename($tar);
            if (preg_match('/^\d{4}-\d{2}-\d{2}_/', $name)) {
                continue; // skip outer pack if left around
            }
            self::extractTar($tar, $stage);
            $applied[] = $name;
        }
        if (is_dir($stage . '/cfg')) {
            self::mergeCfg($stage . '/cfg');
            $applied[] = 'cfg-merge';
        }
        $base = rtrim(base_path(), '/');
        $destMap = [
            'UserFiles' => $base . '/UserFiles',
            'uploads' => $base . '/assets/uploads',
            'themes' => $base . '/themes',
            'log' => $base . '/log',
            'video' => $base . '/var/video',
            'audio' => $base . '/audio',
            'audioParsed' => $base . '/audioParsed',
            'audiochunks' => $base . '/audiochunks',
        ];
        foreach (glob($stage . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $bn = basename($dir);
            if (isset($destMap[$bn])) {
                self::copyTree($dir, $destMap[$bn]);
                $applied[] = $bn;
            }
        }
        return ['ok' => true, 'applied' => $applied];
    }

    public static function mergeCfg(string $restoredCfgDir): void
    {
        $live = base_path('cfg');
        $preserveConnect = [];
        $preserveApp = [];
        $liveConfig = $live . '/config.ini';
        if (is_file($liveConfig)) {
            $parsed = parse_ini_file($liveConfig, true, INI_SCANNER_RAW);
            if (is_array($parsed)) {
                $preserveConnect = is_array($parsed['connect'] ?? null) ? $parsed['connect'] : [];
                $app = is_array($parsed['app'] ?? null) ? $parsed['app'] : [];
                foreach ([
                    'base_url',
                    'session_name',
                    'session_cookie_domain',
                    'session_idle_minutes',
                    'session_remember_days',
                    'agent_token',
                    'cron_token',
                    // Standby host identity — never inherit primary's empty/missing standby.
                    'standby',
                    'standby_probe_url',
                    'standby_allow_bots',
                    'standby_allow_banks',
                    'standby_allow_gcal',
                    'standby_allow_llm',
                    'standby_allow_update',
                ] as $k) {
                    if (array_key_exists($k, $app)) {
                        $preserveApp[$k] = $app[$k];
                    }
                }
            }
        }

        self::copyTree($restoredCfgDir, $live);

        if (is_file($liveConfig) && ($preserveConnect !== [] || $preserveApp !== [])) {
            $parsed = parse_ini_file($liveConfig, true, INI_SCANNER_RAW);
            if (!is_array($parsed)) {
                $parsed = [];
            }
            if ($preserveConnect !== []) {
                $parsed['connect'] = array_merge(is_array($parsed['connect'] ?? null) ? $parsed['connect'] : [], $preserveConnect);
            }
            if ($preserveApp !== []) {
                $parsed['app'] = array_merge(is_array($parsed['app'] ?? null) ? $parsed['app'] : [], $preserveApp);
            }
            self::writeIni($liveConfig, $parsed);
        }
    }

    /** Minimal ustar extractor (files only). Streams file payloads to disk. */
    public static function extractTar(string $tarPath, string $destDir): void
    {
        $fh = fopen($tarPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot open tar');
        }
        $longName = null;
        try {
            while (!feof($fh)) {
                $header = fread($fh, 512);
                if ($header === false || strlen($header) < 512) {
                    break;
                }
                if (trim($header, "\0") === '') {
                    break;
                }
                $name = $longName !== null ? $longName : rtrim(substr($header, 0, 100), "\0");
                $longName = null;
                $sizeOct = trim(substr($header, 124, 12), "\0 ");
                $size = (int)octdec($sizeOct !== '' ? $sizeOct : '0');
                $type = $header[156] ?? '0';
                $pad = $size > 0 ? ((512 - ($size % 512)) % 512) : 0;

                if ($type === 'L') {
                    // GNU long-name: small; keep in memory with a hard cap.
                    if ($size > 1048576) {
                        throw new RuntimeException('tar long name too large');
                    }
                    $payload = $size > 0 ? fread($fh, $size) : '';
                    if ($payload === false) {
                        $payload = '';
                    }
                    if ($pad > 0) {
                        fread($fh, $pad);
                    }
                    $longName = rtrim($payload, "\0");
                    continue;
                }

                if ($type !== '0' && $type !== "\0") {
                    if ($size > 0) {
                        self::streamDiscard($fh, $size + $pad);
                    }
                    continue;
                }

                $name = str_replace('\\', '/', $name);
                if ($name === '' || strpos($name, '..') !== false) {
                    if ($size > 0) {
                        self::streamDiscard($fh, $size + $pad);
                    }
                    continue;
                }
                $out = $destDir . '/' . $name;
                $dir = dirname($out);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                if ($size <= 0) {
                    file_put_contents($out, '');
                    continue;
                }
                $outFh = fopen($out, 'wb');
                if ($outFh === false) {
                    throw new RuntimeException('Cannot write ' . $name);
                }
                try {
                    $left = $size;
                    while ($left > 0) {
                        $chunk = fread($fh, (int)min(1048576, $left));
                        if ($chunk === false || $chunk === '') {
                            throw new RuntimeException('Unexpected EOF in tar entry ' . $name);
                        }
                        fwrite($outFh, $chunk);
                        $left -= strlen($chunk);
                    }
                } finally {
                    fclose($outFh);
                }
                if ($pad > 0) {
                    fread($fh, $pad);
                }
            }
        } finally {
            fclose($fh);
        }
    }

    /** @param resource $fh */
    private static function streamDiscard($fh, int $bytes): void
    {
        $left = max(0, $bytes);
        while ($left > 0) {
            $chunk = fread($fh, (int)min(1048576, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $left -= strlen($chunk);
        }
    }

    /** @return array<string,mixed>|null */
    private static function readManifest(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    private static function copyTree(string $src, string $dest): void
    {
        if (!is_dir($src)) {
            return;
        }
        if (!is_dir($dest)) {
            @mkdir($dest, 0755, true);
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $srcLen = strlen(rtrim(str_replace('\\', '/', $src), '/'));
        foreach ($it as $info) {
            /** @var SplFileInfo $info */
            $path = str_replace('\\', '/', $info->getPathname());
            $rel = ltrim(substr($path, $srcLen), '/');
            $target = rtrim($dest, '/') . '/' . $rel;
            if ($info->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
            } else {
                $td = dirname($target);
                if (!is_dir($td)) {
                    @mkdir($td, 0755, true);
                }
                @copy($path, $target);
            }
        }
    }

    /** @param array<string,mixed> $sections */
    private static function writeIni(string $path, array $sections): void
    {
        $buf = '';
        foreach ($sections as $sec => $pairs) {
            if (!is_array($pairs)) {
                continue;
            }
            $buf .= '[' . $sec . "]\n";
            foreach ($pairs as $k => $v) {
                $buf .= $k . '=' . (string)$v . "\n";
            }
            $buf .= "\n";
        }
        file_put_contents($path, $buf);
    }
}
