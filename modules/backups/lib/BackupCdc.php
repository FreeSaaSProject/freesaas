<?php
declare(strict_types=1);

/**
 * CDC-lite: AFTER I/U/D triggers → base_backup_changes; export collapsed rows for incr packs.
 */
final class BackupCdc
{
    public const JOURNAL = 'base_backup_changes';

    /**
     * @return list<string>
     */
    public static function excludeTables(): array
    {
        $base = BackupDoctor::CDC_EXCLUDE;
        $extra = BackupConfig::cdcExcludeExtra();
        $out = [];
        foreach (array_merge($base, $extra) as $t) {
            $t = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$t) ?? '';
            if ($t !== '' && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    public static function journalReady(): bool
    {
        return Database::tableExists(self::JOURNAL);
    }

    /**
     * @return list<string> base_* tables that should receive triggers
     */
    public static function targetTables(): array
    {
        $pdo = Database::pdo();
        $rows = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'base\\_%'
               AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);
        $exclude = array_fill_keys(self::excludeTables(), true);
        $exclude[self::JOURNAL] = true;
        $exclude['base_backup_probe'] = true;
        $out = [];
        foreach ($rows as $t) {
            $t = (string)$t;
            if (isset($exclude[$t])) {
                continue;
            }
            if (self::primaryKeyColumns($t) === []) {
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    public static function primaryKeyColumns(string $table): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return [];
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'
             ORDER BY ORDINAL_POSITION"
        );
        $st->execute([$table]);
        $cols = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $c = (string)$c;
            if (preg_match('/^[A-Za-z0-9_]+$/', $c)) {
                $cols[] = $c;
            }
        }
        return $cols;
    }

    public static function triggerName(string $table, string $suffix): string
    {
        // MySQL identifier max 64
        $raw = 'bk_' . $table . '_' . $suffix;
        if (strlen($raw) <= 64) {
            return $raw;
        }
        return 'bk_' . substr(hash('sha256', $table . '_' . $suffix), 0, 50) . '_' . $suffix;
    }

    /**
     * Install or refresh all CDC triggers (idempotent).
     *
     * @return array{ok:bool,tables:int,triggers:int,errors:list<string>}
     */
    public static function install(): array
    {
        if (!self::journalReady()) {
            return ['ok' => false, 'tables' => 0, 'triggers' => 0, 'errors' => ['journal missing — migrate first']];
        }
        $pdo = Database::pdo();
        $tables = self::targetTables();
        $errors = [];
        $triggers = 0;
        foreach ($tables as $table) {
            foreach (['ai' => 'INSERT', 'au' => 'UPDATE', 'ad' => 'DELETE'] as $suf => $event) {
                $name = self::triggerName($table, $suf);
                try {
                    $pdo->exec('DROP TRIGGER IF EXISTS `' . $name . '`');
                    $sql = self::createTriggerSql($table, $name, $event, $suf === 'ad' ? 'OLD' : 'NEW');
                    $pdo->exec($sql);
                    $triggers++;
                } catch (Throwable $e) {
                    $errors[] = $table . '/' . $suf . ': ' . $e->getMessage();
                }
            }
        }
        // Drop triggers for tables no longer targeted
        self::dropOrphanTriggers($tables);
        BackupConfig::set('cdc_installed_at', (string)time());
        return [
            'ok' => $errors === [],
            'tables' => count($tables),
            'triggers' => $triggers,
            'errors' => $errors,
        ];
    }

    /**
     * Install triggers for one table (LongJob crumb). Does not finalize orphans / installed_at.
     *
     * @return array{ok:bool,triggers:int,errors:list<string>}
     */
    public static function installTable(string $table): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return ['ok' => false, 'triggers' => 0, 'errors' => ['bad table']];
        }
        if (!self::journalReady()) {
            return ['ok' => false, 'triggers' => 0, 'errors' => ['journal missing — migrate first']];
        }
        $pdo = Database::pdo();
        $errors = [];
        $triggers = 0;
        foreach (['ai' => 'INSERT', 'au' => 'UPDATE', 'ad' => 'DELETE'] as $suf => $event) {
            $name = self::triggerName($table, $suf);
            try {
                $pdo->exec('DROP TRIGGER IF EXISTS `' . $name . '`');
                $sql = self::createTriggerSql($table, $name, $event, $suf === 'ad' ? 'OLD' : 'NEW');
                $pdo->exec($sql);
                $triggers++;
            } catch (Throwable $e) {
                $errors[] = $table . '/' . $suf . ': ' . $e->getMessage();
            }
        }
        return ['ok' => $errors === [], 'triggers' => $triggers, 'errors' => $errors];
    }

    /** After crumb install: drop orphans + stamp. */
    public static function finalizeInstall(array $keepTables): void
    {
        self::dropOrphanTriggers($keepTables);
        BackupConfig::set('cdc_installed_at', (string)time());
    }

    /** @return list<string> */
    public static function listTriggerNames(): array
    {
        $pdo = Database::pdo();
        try {
            $rows = $pdo->query(
                "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
                 WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'bk\\_%'"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $name) {
            $name = (string)$name;
            if (preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $names
     * @return array{dropped:int,errors:list<string>}
     */
    public static function dropTriggersChunk(array $names): array
    {
        $pdo = Database::pdo();
        $dropped = 0;
        $errors = [];
        foreach ($names as $name) {
            $name = (string)$name;
            if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                continue;
            }
            try {
                $pdo->exec('DROP TRIGGER IF EXISTS `' . $name . '`');
                $dropped++;
            } catch (Throwable $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }
        return ['dropped' => $dropped, 'errors' => $errors];
    }

    /**
     * @return array{ok:bool,dropped:int,errors:list<string>}
     */
    public static function uninstall(): array
    {
        $pdo = Database::pdo();
        $errors = [];
        $dropped = 0;
        try {
            $rows = $pdo->query(
                "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
                 WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'bk\\_%'"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return ['ok' => false, 'dropped' => 0, 'errors' => [$e->getMessage()]];
        }
        foreach ($rows as $name) {
            $name = (string)$name;
            if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                continue;
            }
            try {
                $pdo->exec('DROP TRIGGER IF EXISTS `' . $name . '`');
                $dropped++;
            } catch (Throwable $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }
        BackupConfig::set('cdc_installed_at', '0');
        return ['ok' => $errors === [], 'dropped' => $dropped, 'errors' => $errors];
    }

    /**
     * @return array{ok:bool,installed:bool,tables:int,triggers:int,journal_rows:int,cursor:int,max_id:int}
     */
    public static function status(): array
    {
        $tables = self::targetTables();
        $pdo = Database::pdo();
        $trig = 0;
        try {
            $trig = (int)$pdo->query(
                "SELECT COUNT(*) FROM information_schema.TRIGGERS
                 WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'bk\\_%'"
            )->fetchColumn();
        } catch (Throwable $e) {
            $trig = 0;
        }
        $rows = 0;
        $maxId = 0;
        if (self::journalReady()) {
            try {
                $r = $pdo->query('SELECT COUNT(*) AS c, COALESCE(MAX(ID),0) AS m FROM ' . self::JOURNAL)->fetch(PDO::FETCH_ASSOC);
                $rows = (int)($r['c'] ?? 0);
                $maxId = (int)($r['m'] ?? 0);
            } catch (Throwable $e) {
                // ignore
            }
        }
        $expected = count($tables) * 3;
        return [
            'ok' => $trig >= $expected && $expected > 0,
            'installed' => $trig > 0,
            'tables' => count($tables),
            'triggers' => $trig,
            'expected_triggers' => $expected,
            'journal_rows' => $rows,
            'cursor' => BackupConfig::incrCursor(),
            'max_id' => $maxId,
            'cdc_enabled' => BackupConfig::cdcEnabled(),
        ];
    }

    /**
     * Collapse journal (cursor_from, cursor_to] into last op per (tbl, pk).
     *
     * @return list<array{tbl:string,pk_json:string,op:string}>
     */
    public static function collapsedChanges(int $cursorFrom, int $cursorTo, int $chunk = 5000): array
    {
        if (!self::journalReady() || $cursorTo <= $cursorFrom) {
            return [];
        }
        // Keyset scan on PRIMARY + collapse in PHP. GROUP BY/GROUP_CONCAT here forces an on-disk
        // MySQL temp table, which fails with Errcode 28 when the shared host's tmpdir is full.
        $chunk = max(500, min(20000, $chunk));
        $st = Database::pdo()->prepare(
            'SELECT ID, TBL, PK_JSON, OP FROM ' . self::JOURNAL . '
             WHERE ID > ? AND ID <= ?
             ORDER BY ID ASC
             LIMIT ' . $chunk
        );
        $last = [];
        $after = $cursorFrom;
        while ($after < $cursorTo) {
            $st->execute([$after, $cursorTo]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $after = (int)$row['ID'];
                $key = (string)$row['TBL'] . "\0" . (string)$row['PK_JSON'];
                // Re-append so output follows last-change order (REPLACE vs secondary UNIQUE keys).
                unset($last[$key]);
                $last[$key] = (string)$row['OP'];
            }
            if (count($rows) < $chunk) {
                break;
            }
        }
        $out = [];
        foreach ($last as $key => $op) {
            [$tbl, $pkJson] = explode("\0", $key, 2);
            $out[] = ['tbl' => $tbl, 'pk_json' => $pkJson, 'op' => $op];
        }
        return $out;
    }

    /**
     * Write incr SQL file (REPLACE/DELETE) for collapsed keys. Returns counts.
     *
     * @param list<array{tbl:string,pk_json:string,op:string}> $changes
     * @return array{rows_out:int,deletes:int,replaces:int,skipped:int}
     */
    public static function writeIncrSql(string $outPath, array $changes): array
    {
        $fh = fopen($outPath, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Cannot write incr SQL');
        }
        fwrite($fh, "-- FreeSaaS backup incr\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        $replaces = 0;
        $deletes = 0;
        $skipped = 0;
        $pdo = Database::pdo();
        foreach ($changes as $ch) {
            $tbl = (string)($ch['tbl'] ?? '');
            $pkJson = (string)($ch['pk_json'] ?? '');
            $op = (string)($ch['op'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_]+$/', $tbl) || $pkJson === '') {
                $skipped++;
                continue;
            }
            $pkVals = json_decode($pkJson, true);
            if (!is_array($pkVals)) {
                $skipped++;
                continue;
            }
            $cols = self::primaryKeyColumns($tbl);
            if ($cols === [] || count($cols) !== count($pkVals)) {
                $skipped++;
                continue;
            }
            $where = [];
            $bind = [];
            foreach ($cols as $i => $col) {
                $where[] = '`' . $col . '` = ?';
                $bind[] = $pkVals[$i];
            }
            $whereSql = implode(' AND ', $where);
            if ($op === 'D') {
                fwrite($fh, 'DELETE FROM `' . $tbl . '` WHERE ' . self::sqlLiteralWhere($cols, $pkVals) . ";\n");
                $deletes++;
                continue;
            }
            $st = $pdo->prepare('SELECT * FROM `' . $tbl . '` WHERE ' . $whereSql . ' LIMIT 1');
            $st->execute($bind);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                // vanished → delete
                fwrite($fh, 'DELETE FROM `' . $tbl . '` WHERE ' . self::sqlLiteralWhere($cols, $pkVals) . ";\n");
                $deletes++;
                continue;
            }
            fwrite($fh, self::sqlReplace($tbl, $row) . "\n");
            $replaces++;
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        return [
            'rows_out' => $replaces + $deletes,
            'deletes' => $deletes,
            'replaces' => $replaces,
            'skipped' => $skipped,
        ];
    }

    /**
     * Prune journal rows with ID <= $throughId in small chunks.
     * A single huge DELETE is killed on shared hosting (MySQL 1317 / max_statement_time).
     *
     * @return int rows deleted this call (may be partial if $budgetSec exhausted)
     */
    public static function pruneJournal(int $throughId, int $chunk = 2000, float $budgetSec = 25.0): int
    {
        if (!self::journalReady() || $throughId < 1) {
            return 0;
        }
        $chunk = max(100, min(10000, $chunk));
        $budgetSec = max(3.0, $budgetSec);
        $pdo = Database::pdo();
        $sql = 'DELETE FROM ' . self::JOURNAL . ' WHERE ID <= ? ORDER BY ID ASC LIMIT ' . $chunk;
        $st = $pdo->prepare($sql);
        $total = 0;
        $t0 = microtime(true);
        for ($i = 0; $i < 1000; $i++) {
            if ((microtime(true) - $t0) >= $budgetSec) {
                break;
            }
            $st->execute([$throughId]);
            $n = (int)$st->rowCount();
            $total += $n;
            if ($n < $chunk) {
                break;
            }
        }
        return $total;
    }

    /** True when journal still holds rows at or below $throughId. */
    public static function journalHasThrough(int $throughId): bool
    {
        if (!self::journalReady() || $throughId < 1) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT 1 FROM ' . self::JOURNAL . ' WHERE ID <= ? LIMIT 1'
        );
        $st->execute([$throughId]);
        return (bool)$st->fetchColumn();
    }

    public static function maxJournalId(): int
    {
        if (!self::journalReady()) {
            return 0;
        }
        return (int)Database::pdo()->query('SELECT COALESCE(MAX(ID),0) FROM ' . self::JOURNAL)->fetchColumn();
    }

    /**
     * Consistent CDC boundary before a light/full dump.
     *
     * AUTO_INCREMENT can allocate IDs for in-flight transactions that are not yet
     * visible: MAX(ID) alone can skip a late commit (e.g. ID=1 appears after watermark=2).
     * LOCK TABLES … WRITE waits for those writers to finish, then freezes a visible MAX(ID).
     *
     * @return array{id:int,ts:string,locked:bool}
     */
    public static function captureWatermark(): array
    {
        if (!self::journalReady()) {
            return ['id' => 0, 'ts' => '', 'locked' => false];
        }
        $pdo = Database::pdo();
        $locked = false;
        try {
            $pdo->exec('LOCK TABLES `' . self::JOURNAL . '` WRITE');
            $locked = true;
        } catch (Throwable $e) {
            error_log('[backups] cdc watermark LOCK TABLES failed: ' . $e->getMessage());
        }
        try {
            $row = $pdo->query(
                'SELECT COALESCE(MAX(ID),0) AS m, NOW() AS t FROM `' . self::JOURNAL . '`'
            )->fetch(PDO::FETCH_ASSOC);
            return [
                'id' => (int)($row['m'] ?? 0),
                'ts' => (string)($row['t'] ?? ''),
                'locked' => $locked,
            ];
        } finally {
            if ($locked) {
                try {
                    $pdo->exec('UNLOCK TABLES');
                } catch (Throwable $e) {
                    error_log('[backups] cdc watermark UNLOCK failed: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Apply incr SQL file against current DB (standby).
     *
     * @return array{ok:bool,statements:int,error:string}
     */
    public static function applyIncrSqlFile(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'statements' => 0, 'error' => 'file missing'];
        }
        $sql = (string)file_get_contents($path);
        if ($sql === '') {
            return ['ok' => true, 'statements' => 0, 'error' => ''];
        }
        $pdo = Database::pdo();
        $n = 0;
        try {
            // Match producer preamble; keep FK off for the whole apply batch.
            $pdo->exec('SET NAMES utf8mb4');
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            // Split on ;\n — statements we generate are single-line
            $parts = preg_split('/;\s*\n/', $sql) ?: [];
            foreach ($parts as $stmt) {
                $stmt = trim($stmt);
                if ($stmt === '' || strpos($stmt, '--') === 0) {
                    continue;
                }
                $pdo->exec($stmt);
                $n++;
            }
            return ['ok' => true, 'statements' => $n, 'error' => ''];
        } catch (Throwable $e) {
            return ['ok' => false, 'statements' => $n, 'error' => $e->getMessage()];
        } finally {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    private static function createTriggerSql(string $table, string $name, string $event, string $rowAlias): string
    {
        $cols = self::primaryKeyColumns($table);
        $args = [];
        foreach ($cols as $c) {
            $args[] = $rowAlias . '.`' . $c . '`';
        }
        $jsonExpr = 'JSON_ARRAY(' . implode(', ', $args) . ')';
        $op = $event === 'INSERT' ? 'I' : ($event === 'UPDATE' ? 'U' : 'D');
        return 'CREATE TRIGGER `' . $name . '` AFTER ' . $event . ' ON `' . $table . '`
FOR EACH ROW BEGIN
  INSERT INTO `' . self::JOURNAL . '` (`TBL`, `PK_JSON`, `OP`, `TS`)
  VALUES (' . Database::pdo()->quote($table) . ', CAST(' . $jsonExpr . ' AS CHAR(512)), '
            . Database::pdo()->quote($op) . ', NOW());
END';
    }

    /**
     * @param list<string> $keepTables
     */
    private static function dropOrphanTriggers(array $keepTables): void
    {
        $pdo = Database::pdo();
        $keepNames = [];
        foreach ($keepTables as $t) {
            foreach (['ai', 'au', 'ad'] as $suf) {
                $keepNames[self::triggerName($t, $suf)] = true;
            }
        }
        try {
            $rows = $pdo->query(
                "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
                 WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'bk\\_%'"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return;
        }
        foreach ($rows as $name) {
            $name = (string)$name;
            if (isset($keepNames[$name]) || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                continue;
            }
            try {
                $pdo->exec('DROP TRIGGER IF EXISTS `' . $name . '`');
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * Quote a SQL identifier (supports legacy unicode column names, e.g. Cyrillic Е).
     */
    private static function quoteIdent(string $name): string
    {
        $name = str_replace("\0", '', $name);
        if ($name === '') {
            throw new RuntimeException('Empty SQL identifier');
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function sqlReplace(string $table, array $row): string
    {
        $cols = [];
        $vals = [];
        foreach ($row as $k => $v) {
            $k = (string)$k;
            // Skip PDO numeric keys only; keep unicode / mixed-script names (legacy DBs).
            if ($k === '' || ctype_digit($k)) {
                continue;
            }
            $cols[] = self::quoteIdent($k);
            $vals[] = self::sqlQuote($v);
        }
        if ($cols === []) {
            throw new RuntimeException('REPLACE INTO ' . $table . ': no columns');
        }
        return 'REPLACE INTO ' . self::quoteIdent($table)
            . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ');';
    }

    /**
     * @param list<string> $cols
     * @param list<mixed> $vals
     */
    private static function sqlLiteralWhere(array $cols, array $vals): string
    {
        $parts = [];
        foreach ($cols as $i => $c) {
            $parts[] = self::quoteIdent((string)$c) . ' = ' . self::sqlQuote($vals[$i] ?? null);
        }
        return implode(' AND ', $parts);
    }

    /** @param mixed $v */
    private static function sqlQuote($v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        return Database::pdo()->quote((string)$v);
    }
}
