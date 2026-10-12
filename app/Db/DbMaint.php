<?php
declare(strict_types=1);

/** DB hub: CHECK/REPAIR/OPTIMIZE, diagnose, DROP. */
final class DbMaint
{
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
        $job = DbJobs::jobStart($op, ['tables' => DbTables::filterValidTables($tables)]);
        $id = (string)$job['id'];
        while (($job['status'] ?? '') === 'running') {
            $job = DbJobs::jobTick($id);
        }
        if (($job['status'] ?? '') !== 'done') {
            throw new RuntimeException((string)($job['error'] ?? $op . ' failed'));
        }
        /** @var list<array{table:string,Msg_text?:string,Msg_type?:string,status:string}> $report */
        $report = $job['result']['report'] ?? [];
        return $report;
    }

    // --- Chunked jobs (UI progress + fat DB safety) -------------------------

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
        $tables = DbTables::filterValidTables($tables);
        if ($tables === []) {
            return [];
        }
        $owned = $ownedTables !== null ? DbTables::filterValidTables($ownedTables) : DbTables::ownedTables();

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
                if ($t === '' || !DbTables::isValidTableName($t)) {
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
        $tables = DbTables::filterValidTables($tables);
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
        $tables = DbTables::filterValidTables($tables);
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

    public static function tickMaint(array $job): array
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
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function tickDiagnose(array $job): array
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
        $owned = DbTables::filterValidTables($job['owned'] ?? []);
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

}
