<?php
declare(strict_types=1);

final class ProcentsRepository
{
    /** @var array<string,array<int,array<string,float>>> */
    private static $dayStaffPayCache = [];

    public static function canSeeOthers(): bool
    {
        return Auth::capability('CAN_SEE_SUM_OTHERS');
    }

    public static function canAudit(): bool
    {
        return Auth::capability('SUPERADMIN_ACCESS');
    }

    /**
     * Day headers for all report periods in one batched pass (avoids per-day SQL).
     *
     * @param list<array<string,mixed>> $periods from report()
     * @return array<string,list<array<string,mixed>>> period label => days
     */
    public static function auditDaysByPeriods(int $operatorId, array $periods): array
    {
        $out = [];
        if ($operatorId < 1 || $periods === []) {
            return $out;
        }
        $minStart = 0;
        $maxEnd = 0;
        foreach ($periods as $period) {
            $label = (string)($period['label'] ?? '');
            $out[$label] = [];
            $s = (int)($period['start'] ?? 0);
            $e = (int)($period['end'] ?? 0);
            if ($s < 1 || $e < $s) {
                continue;
            }
            if ($minStart < 1 || $s < $minStart) {
                $minStart = $s;
            }
            if ($e > $maxEnd) {
                $maxEnd = $e;
            }
        }
        if ($minStart < 1 || $maxEnd < $minStart) {
            return $out;
        }

        $allDays = self::auditDays($operatorId, $minStart, $maxEnd);
        foreach ($allDays as $day) {
            $dayStart = (int)($day['day_start'] ?? 0);
            if ($dayStart < 1) {
                continue;
            }
            $ym = date('Y-m', $dayStart);
            if (!array_key_exists($ym, $out)) {
                continue;
            }
            $out[$ym][] = $day;
        }
        return $out;
    }

    /**
     * Day headers for salary audit tree (totals only; lines via auditDayDetail).
     * Batched: a handful of SQL queries for the whole range, not per calendar day.
     *
     * @return list<array{
     *   day:string,
     *   day_start:int,
     *   label:string,
     *   total:float,
     *   salary:float,
     *   salary_fix:float,
     *   salary_terminal:float,
     *   salary_sales:float,
     *   salary_audio:float,
     *   salary_scripts:float
     * }>
     */
    public static function auditDays(int $operatorId, int $start, int $end): array
    {
        $out = [];
        if ($operatorId < 1 || $end <= $start || !Database::tableExists('base_procedures')) {
            return $out;
        }
        if (!Database::tableExists('base_procent_history')) {
            return $out;
        }

        $rangeStart = (int)mktime(0, 0, 0, (int)date('n', $start), (int)date('j', $start), (int)date('Y', $start));
        $rangeEndDay = (int)mktime(0, 0, 0, (int)date('n', $end), (int)date('j', $end), (int)date('Y', $end));
        $rangeEnd = (int)mktime(0, 0, 0, (int)date('n', $rangeEndDay), (int)date('j', $rangeEndDay) + 1, (int)date('Y', $rangeEndDay));
        if ($rangeEnd <= $rangeStart) {
            return $out;
        }

        $opByDay = self::sumsByTypeByDay($operatorId, $rangeStart, $rangeEnd, 'ID_OPERATOR');
        $adminByDay = self::sumsByTypeByDay($operatorId, $rangeStart, $rangeEnd, 'ID_ADMIN');
        $shiftByDay = self::shiftUnitsByDay($operatorId, $rangeStart, $rangeEnd);
        $salesByDay = Database::tableExists('base_sales')
            ? self::salesByTypeByDay($operatorId, $rangeStart, $rangeEnd)
            : [];
        $audioHoursByDay = self::audioHoursByDay($operatorId, $rangeStart, $rangeEnd);
        $scriptByDay = self::scriptScoreByDay($operatorId, $rangeStart, $rangeEnd);
        $rateHistory = self::rateHistoryAsc($operatorId);

        $dayKeys = [];
        foreach ([$opByDay, $adminByDay, $shiftByDay, $salesByDay, $audioHoursByDay, $scriptByDay] as $map) {
            foreach (array_keys($map) as $dk) {
                $dayKeys[(string)$dk] = true;
            }
        }
        if ($dayKeys === []) {
            return $out;
        }
        krsort($dayKeys, SORT_STRING);

        foreach (array_keys($dayKeys) as $dayKey) {
            $parts = explode('-', $dayKey);
            if (count($parts) !== 3) {
                continue;
            }
            $dayStart = (int)mktime(0, 0, 0, (int)$parts[1], (int)$parts[2], (int)$parts[0]);
            $dayEnd = (int)mktime(0, 0, 0, (int)$parts[1], (int)$parts[2] + 1, (int)$parts[0]);
            if ($dayStart < 1 || $dayEnd <= $dayStart || $dayStart > $end || $dayEnd <= $start) {
                continue;
            }
            $mid = $dayStart + 12 * 3600;
            $rates = self::rateAtFromHistory($rateHistory, $mid);
            if ($rates === null) {
                continue;
            }
            $content = is_array($rates['content'] ?? null) ? $rates['content'] : [];
            $contentTerminal = is_array($rates['content_terminal'] ?? null) ? $rates['content_terminal'] : [];
            $contentSales = is_array($rates['content_sales'] ?? null) ? $rates['content_sales'] : [];

            $salary = 0.0;
            foreach (($opByDay[$dayKey] ?? []) as $tid => $sum) {
                $rate = (float)str_replace(',', '.', (string)($content[$tid] ?? $content[(string)$tid] ?? 0));
                $salary += round((float)$sum * $rate);
            }

            $salaryFix = 0.0;
            $lossFix = (float)($rates['loss_fix'] ?? 0);
            $units = (float)($shiftByDay[$dayKey] ?? 0.0);
            if ($lossFix != 0.0 && $units > 0.0) {
                $salaryFix = round($units * $lossFix);
            }

            $salaryTerminal = 0.0;
            foreach (($adminByDay[$dayKey] ?? []) as $tid => $sum) {
                $rate = (float)str_replace(',', '.', (string)($contentTerminal[$tid] ?? $contentTerminal[(string)$tid] ?? 0));
                if ($rate == 2.0) {
                    continue;
                }
                $salaryTerminal += round((float)$sum * $rate);
            }

            $salarySales = 0.0;
            foreach (($salesByDay[$dayKey] ?? []) as $tid => $sum) {
                $rate = (float)str_replace(',', '.', (string)($contentSales[$tid] ?? $contentSales[(string)$tid] ?? 0));
                $salarySales += round((float)$sum * $rate);
            }

            $salaryAudio = 0.0;
            $hourRate = (float)($rates['audio_hour_rate'] ?? 0);
            $hours = (float)($audioHoursByDay[$dayKey] ?? 0.0);
            if ($hourRate != 0.0 && $hours > 0.0) {
                $salaryAudio = round($hours * $hourRate);
            }

            $salaryScripts = 0.0;
            $scriptRate = (float)($rates['script_score_rate'] ?? 0);
            $scoreSum = (float)($scriptByDay[$dayKey] ?? 0.0);
            if ($scriptRate != 0.0 && $scoreSum > 0.0) {
                $salaryScripts = round($scoreSum * $scriptRate);
            }

            $total = $salary + $salaryFix + $salaryTerminal + $salarySales + $salaryAudio + $salaryScripts;
            if ($total <= 0.0) {
                continue;
            }
            $out[] = [
                'day' => $dayKey,
                'day_start' => $dayStart,
                'label' => date('d.m.Y', $dayStart),
                'total' => $total,
                'salary' => $salary,
                'salary_fix' => $salaryFix,
                'salary_terminal' => $salaryTerminal,
                'salary_sales' => $salarySales,
                'salary_audio' => $salaryAudio,
                'salary_scripts' => $salaryScripts,
            ];
        }
        return $out;
    }

    /**
     * Line-level accrual detail for one calendar day (superadmin audit).
     *
     * @return array{
     *   day:string,
     *   day_start:int,
     *   day_end:int,
     *   total:float,
     *   lines:list<array<string,mixed>>
     * }
     */
    public static function auditDayDetail(int $operatorId, int $dayStart): array
    {
        $empty = [
            'day' => '',
            'day_start' => 0,
            'day_end' => 0,
            'total' => 0.0,
            'lines' => [],
        ];
        if ($operatorId < 1 || $dayStart < 1) {
            return $empty;
        }
        $dayStart = (int)mktime(0, 0, 0, (int)date('n', $dayStart), (int)date('j', $dayStart), (int)date('Y', $dayStart));
        $dayEnd = (int)mktime(0, 0, 0, (int)date('n', $dayStart), (int)date('j', $dayStart) + 1, (int)date('Y', $dayStart));
        if ($dayEnd <= $dayStart) {
            return $empty;
        }

        $mid = $dayStart + 12 * 3600;
        $rates = class_exists('RatesRepository', false)
            ? RatesRepository::latestForOperator($operatorId, $mid)
            : null;
        if (!$rates) {
            return [
                'day' => date('Y-m-d', $dayStart),
                'day_start' => $dayStart,
                'day_end' => $dayEnd,
                'total' => 0.0,
                'lines' => [],
            ];
        }

        $content = is_array($rates['content'] ?? null) ? $rates['content'] : [];
        $contentTerminal = is_array($rates['content_terminal'] ?? null) ? $rates['content_terminal'] : [];
        $contentSales = is_array($rates['content_sales'] ?? null) ? $rates['content_sales'] : [];
        $types = class_exists('ProcedureTypesRepository', false) ? ProcedureTypesRepository::listAll() : [];
        $typeMap = [];
        foreach ($types as $t) {
            $typeMap[(int)$t['id']] = $t;
        }

        $lines = [];
        $total = 0.0;

        $shiftPay = self::shiftFixAccrual($operatorId, $dayStart, $dayEnd, $rates);
        foreach ($shiftPay['rows'] as $row) {
            $accrued = (float)$row['accrued'];
            $total += $accrued;
            $lines[] = [
                'kind' => 'shift',
                'time' => '',
                'role' => 'shift',
                'type' => (string)$row['type'],
                'descr' => (string)$row['descr'],
                'sum' => (float)$row['sum'],
                'rate' => (float)$row['rate'],
                'accrued' => $accrued,
                'rate_kind' => 'absolute',
                'procedure_id' => 0,
                'event_id' => 0,
            ];
        }

        foreach (self::procedureLinesForWindow($operatorId, $dayStart, $dayEnd, 'ID_OPERATOR') as $proc) {
            $tid = (int)$proc['type_id'];
            $sum = (float)$proc['summ'];
            $rate = (float)str_replace(',', '.', (string)($content[$tid] ?? $content[(string)$tid] ?? 0));
            $accrued = round($sum * $rate);
            if ($accrued == 0.0 && $sum == 0.0) {
                continue;
            }
            $type = $typeMap[$tid] ?? null;
            $descrParts = [];
            if ($type) {
                $descrParts[] = (string)($type['descr'] ?? '');
            }
            if ((string)$proc['product_name'] !== '') {
                $descrParts[] = (string)$proc['product_name'];
            }
            if ((string)$proc['client_name'] !== '') {
                $descrParts[] = (string)$proc['client_name'];
            }
            $total += $accrued;
            $lines[] = [
                'kind' => 'procedure',
                'time' => date('H:i', (int)$proc['dtstart']),
                'role' => 'operator',
                'type' => $type ? (string)($type['type'] ?? '') : ('#' . $tid),
                'descr' => implode(' · ', array_filter($descrParts, static function ($s) {
                    return trim($s) !== '';
                })),
                'sum' => $sum,
                'rate' => $rate,
                'accrued' => $accrued,
                'rate_kind' => 'fraction',
                'procedure_id' => (int)$proc['id'],
                'event_id' => (int)$proc['event_id'],
                'dtstart' => (int)$proc['dtstart'],
            ];
        }

        foreach (self::procedureLinesForWindow($operatorId, $dayStart, $dayEnd, 'ID_ADMIN') as $proc) {
            $tid = (int)$proc['type_id'];
            $rate = (float)str_replace(',', '.', (string)($contentTerminal[$tid] ?? $contentTerminal[(string)$tid] ?? 0));
            if ($rate == 2.0) {
                continue;
            }
            $sum = (float)$proc['summ'];
            $accrued = round($sum * $rate);
            if ($accrued == 0.0 && $sum == 0.0) {
                continue;
            }
            $type = $typeMap[$tid] ?? null;
            $descrParts = [];
            if ($type) {
                $descrParts[] = (string)($type['descr'] ?? '');
            }
            if ((string)$proc['product_name'] !== '') {
                $descrParts[] = (string)$proc['product_name'];
            }
            if ((string)$proc['client_name'] !== '') {
                $descrParts[] = (string)$proc['client_name'];
            }
            $total += $accrued;
            $lines[] = [
                'kind' => 'terminal',
                'time' => date('H:i', (int)$proc['dtstart']),
                'role' => 'terminal',
                'type' => $type ? (string)($type['type'] ?? '') : ('#' . $tid),
                'descr' => implode(' · ', array_filter($descrParts, static function ($s) {
                    return trim($s) !== '';
                })),
                'sum' => $sum,
                'rate' => $rate,
                'accrued' => $accrued,
                'rate_kind' => 'fraction',
                'procedure_id' => (int)$proc['id'],
                'event_id' => (int)$proc['event_id'],
                'dtstart' => (int)$proc['dtstart'],
            ];
        }

        if (Database::tableExists('base_sales')) {
            $salesByType = self::salesByType($operatorId, $dayStart, $dayEnd);
            foreach ($salesByType as $tid => $sum) {
                $rate = (float)str_replace(',', '.', (string)($contentSales[$tid] ?? $contentSales[(string)$tid] ?? 0));
                $accrued = round((float)$sum * $rate);
                if ($accrued == 0.0) {
                    continue;
                }
                $type = $typeMap[(int)$tid] ?? null;
                $total += $accrued;
                $lines[] = [
                    'kind' => 'sales',
                    'time' => '',
                    'role' => 'sales',
                    'type' => $type ? (string)$type['type'] : ('#' . (int)$tid),
                    'descr' => $type ? (string)$type['descr'] : '',
                    'sum' => (float)$sum,
                    'rate' => $rate,
                    'accrued' => $accrued,
                    'rate_kind' => 'fraction',
                    'procedure_id' => 0,
                    'event_id' => 0,
                ];
            }
        }

        $audioPay = self::audioAccrualForPeriod($operatorId, $dayStart, $dayEnd, $rates);
        foreach ($audioPay['rows_audio'] as $row) {
            $accrued = (float)$row['accrued'];
            $total += $accrued;
            $lines[] = [
                'kind' => 'audio',
                'time' => '',
                'role' => 'audio',
                'type' => (string)$row['type'],
                'descr' => (string)$row['descr'],
                'sum' => (float)$row['sum'],
                'rate' => (float)$row['rate'],
                'accrued' => $accrued,
                'rate_kind' => 'absolute',
                'procedure_id' => 0,
                'event_id' => 0,
            ];
        }
        foreach ($audioPay['rows_scripts'] as $row) {
            $accrued = (float)$row['accrued'];
            $total += $accrued;
            $lines[] = [
                'kind' => 'scripts',
                'time' => '',
                'role' => 'scripts',
                'type' => (string)$row['type'],
                'descr' => (string)$row['descr'],
                'sum' => (float)$row['sum'],
                'rate' => (float)$row['rate'],
                'accrued' => $accrued,
                'rate_kind' => 'absolute',
                'procedure_id' => 0,
                'event_id' => 0,
            ];
        }

        // Keep procedure/terminal chronological; keep non-time rows at top (shifts) then by kind order already built.
        usort($lines, static function (array $a, array $b): int {
            $ta = (int)($a['dtstart'] ?? 0);
            $tb = (int)($b['dtstart'] ?? 0);
            if ($ta > 0 || $tb > 0) {
                if ($ta === 0) {
                    return -1;
                }
                if ($tb === 0) {
                    return 1;
                }
                if ($ta !== $tb) {
                    return $ta <=> $tb;
                }
            }
            return ((int)($a['procedure_id'] ?? 0)) <=> ((int)($b['procedure_id'] ?? 0));
        });

        return [
            'day' => date('Y-m-d', $dayStart),
            'day_start' => $dayStart,
            'day_end' => $dayEnd,
            'total' => $total,
            'lines' => $lines,
        ];
    }

    /**
     * @return array<string,array<int,float>> day Y-m-d => type_id => sum
     */
    private static function sumsByTypeByDay(int $operatorId, int $start, int $end, string $userCol): array
    {
        $out = [];
        if ($operatorId < 1 || $end <= $start || !in_array($userCol, ['ID_OPERATOR', 'ID_ADMIN'], true)
            || !Database::tableExists('base_procedures')
        ) {
            return $out;
        }
        $st = Database::pdo()->prepare(
            "SELECT FROM_UNIXTIME(DTSTART, '%Y-%m-%d') AS d, TYPE AS type_id, SUM(SUMM) AS summ
             FROM base_procedures
             WHERE `$userCol` = ? AND DTSTART > ? AND DTSTART < ?
             GROUP BY d, TYPE"
        );
        $st->execute([$operatorId, $start, $end]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $d = (string)($r['d'] ?? '');
            if ($d === '') {
                continue;
            }
            $out[$d][(int)$r['type_id']] = (float)$r['summ'];
        }
        return $out;
    }

    /**
     * @return array<string,float> day Y-m-d => shift units
     */
    private static function shiftUnitsByDay(int $operatorId, int $start, int $end): array
    {
        $out = [];
        if ($operatorId < 1 || $end <= $start || !Database::tableExists('base_workweeks')) {
            return $out;
        }
        $st = Database::pdo()->prepare(
            "SELECT FROM_UNIXTIME(DT, '%Y-%m-%d') AS d, PRICE_COL AS price_col
             FROM base_workweeks
             WHERE ID_OPERATOR = ? AND DT >= ? AND DT < ?"
        );
        $st->execute([$operatorId, $start, $end]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $d = (string)($row['d'] ?? '');
            $pc = (int)($row['price_col'] ?? 0);
            if ($d === '' || $pc < 1) {
                continue;
            }
            $units = class_exists(WorkweeksRepository::class, false)
                ? WorkweeksRepository::shiftPayUnits($pc)
                : 1.0;
            if ($units > 0.0) {
                $out[$d] = ($out[$d] ?? 0.0) + $units;
            }
        }
        return $out;
    }

    /**
     * @return array<string,array<int,float>> day Y-m-d => type_id => sum
     */
    private static function salesByTypeByDay(int $operatorId, int $start, int $end): array
    {
        $out = [];
        if ($operatorId < 1 || $end <= $start || !Database::tableExists('base_sales')) {
            return $out;
        }
        $hasOp = Database::columnExists('base_sales', 'ID_OPERATOR');
        $hasDt = Database::columnExists('base_sales', 'DT');
        $hasStatus = Database::columnExists('base_sales', 'STATUS');
        $hasSum = Database::columnExists('base_sales', 'SUMM') || Database::columnExists('base_sales', 'SUM');
        $sumCol = Database::columnExists('base_sales', 'SUMM') ? 'SUMM' : 'SUM';
        $typeCol = Database::columnExists('base_sales', 'TYPE')
            ? 'TYPE'
            : (Database::columnExists('base_sales', 'ID_TYPE') ? 'ID_TYPE' : null);
        if (!$hasOp || !$hasDt || !$hasSum || $typeCol === null) {
            return $out;
        }
        $sql = "SELECT FROM_UNIXTIME(DT, '%Y-%m-%d') AS d, `$typeCol` AS type_id, SUM(`$sumCol`) AS summ
                FROM base_sales
                WHERE ID_OPERATOR = ? AND DT > ? AND DT < ?";
        $params = [$operatorId, $start, $end];
        if ($hasStatus) {
            $sql .= ' AND STATUS = ?';
            $params[] = '2';
        }
        $sql .= ' GROUP BY d, `' . $typeCol . '`';
        try {
            $st = Database::pdo()->prepare($sql);
            $st->execute($params);
        } catch (Throwable $e) {
            return $out;
        }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $d = (string)($r['d'] ?? '');
            if ($d === '') {
                continue;
            }
            $out[$d][(int)$r['type_id']] = (float)$r['summ'];
        }
        return $out;
    }

    /**
     * Accounted OffRecord hours per calendar day (same rules as audioHoursStats).
     *
     * @return array<string,float> day Y-m-d => hours
     */
    private static function audioHoursByDay(int $adminId, int $start, int $end): array
    {
        $out = [];
        if ($adminId < 1 || $end <= $start || !Database::tableExists('base_records')) {
            return $out;
        }
        $bounds = self::audioDayBounds();
        $st = Database::pdo()->prepare(
            'SELECT PLAYTIME AS playtime, DT_RECORDED AS dt, DISABLED AS disabled
             FROM base_records
             WHERE ID_ADMIN = ? AND DT_RECORDED >= ? AND DT_RECORDED < ?
               AND (DISABLED IS NULL OR DISABLED = 0 OR DISABLED = \'0\')'
        );
        $st->execute([$adminId, $start, $end]);
        $playByDay = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $dt = (int)($row['dt'] ?? 0);
            $play = max(0, (int)($row['playtime'] ?? 0));
            if ($dt < 1 || $play < 1) {
                continue;
            }
            if (!self::recordFullyInWindow($dt, $play, $bounds, 'OffRecord')) {
                continue;
            }
            $d = date('Y-m-d', $dt);
            $playByDay[$d] = ($playByDay[$d] ?? 0) + $play;
        }
        foreach ($playByDay as $d => $playSum) {
            if ($playSum > 0) {
                $out[$d] = round($playSum / 3600, 1);
            }
        }
        return $out;
    }

    /**
     * @return array<string,float> day Y-m-d => SCORE sum
     */
    private static function scriptScoreByDay(int $adminId, int $start, int $end): array
    {
        $out = [];
        if ($adminId < 1 || $end <= $start || !Database::tableExists('base_records_collections')) {
            return $out;
        }
        if (!Database::columnExists('base_records_collections', 'SCORE')
            || !Database::columnExists('base_records_collections', 'ID_ADMIN')
            || !Database::columnExists('base_records_collections', 'DT_START')
        ) {
            return $out;
        }
        $st = Database::pdo()->prepare(
            "SELECT FROM_UNIXTIME(DT_START, '%Y-%m-%d') AS d, COALESCE(SUM(SCORE), 0) AS score_sum
             FROM base_records_collections
             WHERE ID_ADMIN = ? AND DT_START >= ? AND DT_START < ? AND SCORE > 0
             GROUP BY d"
        );
        $st->execute([$adminId, $start, $end]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $d = (string)($r['d'] ?? '');
            if ($d === '') {
                continue;
            }
            $out[$d] = (float)$r['score_sum'];
        }
        return $out;
    }

    /**
     * Rate history ascending by DT (for in-memory latest-at lookups).
     *
     * @return list<array<string,mixed>>
     */
    private static function rateHistoryAsc(int $operatorId): array
    {
        if ($operatorId < 1 || !class_exists('RatesRepository', false)) {
            return [];
        }
        $res = RatesRepository::list([
            'operator_id' => $operatorId,
            'order' => 'h.DT ASC, h.ID ASC',
            'limit' => 0,
        ]);
        return is_array($res['items'] ?? null) ? $res['items'] : [];
    }

    /**
     * @param list<array<string,mixed>> $historyAsc
     * @return array<string,mixed>|null
     */
    private static function rateAtFromHistory(array $historyAsc, int $ts): ?array
    {
        if ($historyAsc === [] || $ts < 1) {
            return null;
        }
        $best = null;
        foreach ($historyAsc as $row) {
            $dt = (int)($row['dt'] ?? 0);
            if ($dt > $ts) {
                break;
            }
            $best = $row;
        }
        return $best;
    }

    /**
     * Procedure rows for audit (no ENABLED filter — same as sumsByType).
     *
     * @return list<array{id:int,dtstart:int,type_id:int,summ:float,event_id:int,product_name:string,client_name:string}>
     */
    private static function procedureLinesForWindow(int $operatorId, int $start, int $end, string $userCol): array
    {
        if ($operatorId < 1 || $end <= $start || !in_array($userCol, ['ID_OPERATOR', 'ID_ADMIN'], true)
            || !Database::tableExists('base_procedures')
        ) {
            return [];
        }
        $joins = '';
        $extra = 'NULL AS product_name, NULL AS client_name';
        if (Database::tableExists('base_products')) {
            $joins .= ' LEFT JOIN base_products g ON g.id = p.ID_GOOD';
            $extra = 'g.name AS product_name, NULL AS client_name';
        }
        if (Database::tableExists('base_clients')) {
            $joins .= ' LEFT JOIN base_clients c ON c.id = p.ID_USER';
            if (strpos($extra, 'NULL AS client_name') !== false) {
                $extra = str_replace('NULL AS client_name', 'c.name AS client_name', $extra);
            } else {
                $extra .= ', c.name AS client_name';
            }
        }
        $st = Database::pdo()->prepare(
            "SELECT p.ID AS id, p.DTSTART AS dtstart, p.TYPE AS type_id, p.SUMM AS summ,
                    p.ID_EVENT_PARENT AS event_id, $extra
             FROM base_procedures p
             $joins
             WHERE p.`$userCol` = ? AND p.DTSTART > ? AND p.DTSTART < ?
             ORDER BY p.DTSTART ASC, p.ID ASC"
        );
        $st->execute([$operatorId, $start, $end]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'id' => (int)($r['id'] ?? 0),
                'dtstart' => (int)($r['dtstart'] ?? 0),
                'type_id' => (int)($r['type_id'] ?? 0),
                'summ' => (float)($r['summ'] ?? 0),
                'event_id' => (int)($r['event_id'] ?? 0),
                'product_name' => (string)($r['product_name'] ?? ''),
                'client_name' => (string)($r['client_name'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Build month periods and accrual rows for an operator.
     * When year=0 and month=0: recent window of $limit months (default 2 = current + previous).
     *
     * @return array{
     *   periods:list<array<string,mixed>>,
     *   grand_total:float,
     *   soft_notes:list<string>,
     *   range_label:string,
     *   limit:int,
     *   has_more:bool,
     *   mode:string
     * }
     */
    public static function report(int $operatorId, int $year = 0, int $month = 0, int $limit = 0): array
    {
        $empty = [
            'periods' => [],
            'grand_total' => 0.0,
            'soft_notes' => [],
            'range_label' => '',
            'limit' => 0,
            'has_more' => false,
            'mode' => 'empty',
        ];
        $soft = [];
        if ($operatorId < 1 || !Database::tableExists('base_procedures')) {
            return $empty;
        }

        if (!Database::tableExists('base_sales')) {
            $soft[] = t('procents.soft.sales_missing');
        }
        if (!Database::tableExists('base_good_sticker_outs') && !Database::tableExists('base_goods_out')) {
            $soft[] = t('procents.soft.stocks_missing');
        }
        if (!Database::tableExists('base_records')) {
            $soft[] = t('procents.soft.records_missing');
        }

        $bounds = self::procedureBounds($operatorId);
        if ($bounds === null) {
            $empty['soft_notes'] = $soft;
            return $empty;
        }

        $boundStart = (int)$bounds['start'];
        $boundEnd = (int)$bounds['end'];
        $startTs = $boundStart;
        $endTs = $boundEnd;
        $mode = 'bounds';
        $effectiveLimit = 0;
        $hasMore = false;

        if ($year > 0 && $month > 0) {
            $startTs = mktime(0, 0, 0, $month, 1, $year) ?: $startTs;
            $endTs = mktime(23, 59, 59, $month, (int)date('t', $startTs), $year) ?: $endTs;
            $mode = 'month';
        } elseif ($year > 0) {
            $startTs = mktime(0, 0, 0, 1, 1, $year) ?: $startTs;
            $endTs = mktime(23, 59, 59, 12, 31, $year) ?: $endTs;
            $mode = 'year';
        } else {
            // Default recent window: current calendar month + older months (limit total).
            $effectiveLimit = $limit > 0 ? min(120, $limit) : 2;
            $nowY = (int)date('Y');
            $nowM = (int)date('n');
            $endTs = mktime(23, 59, 59, $nowM, (int)date('t'), $nowY) ?: $endTs;
            // Oldest month in window = current month shifted back (limit-1).
            $startTs = mktime(0, 0, 0, $nowM - ($effectiveLimit - 1), 1, $nowY) ?: $startTs;
            $windowStart = $startTs;
            if ($boundStart > $startTs) {
                $startTs = $boundStart;
            }
            if ($boundEnd < $endTs) {
                $endTs = $boundEnd;
            }
            $hasMore = $boundStart < $windowStart;
            $mode = 'recent';
        }

        if (!self::canSeeOthers() && $endTs > time()) {
            $endTs = time();
        }
        if ($endTs < $startTs) {
            $empty['soft_notes'] = $soft;
            $empty['mode'] = $mode;
            $empty['limit'] = $effectiveLimit;
            return $empty;
        }

        $months = self::monthRange($startTs, $endTs);
        $types = ProcedureTypesRepository::listAll();
        $typeMap = [];
        foreach ($types as $t) {
            $typeMap[(int)$t['id']] = $t;
        }

        $periods = [];
        $grand = 0.0;
        foreach ($months as $m) {
            $scanStart = (int)$m['start'];
            $scanEnd = (int)$m['end'];
            $rates = RatesRepository::latestForOperator($operatorId, $scanEnd);
            $block = [
                'label' => date('Y-m', $scanStart),
                'start' => $scanStart,
                'end' => $scanEnd,
                'rates_descr' => $rates ? (string)$rates['descr'] : '',
                'offers' => $rates ? (int)$rates['offers'] : 0,
                'rows_shifts' => [],
                'rows_proc' => [],
                'rows_terminal' => [],
                'rows_sales' => [],
                'rows_audio' => [],
                'rows_scripts' => [],
                'total' => 0.0,
            ];
            if (!$rates) {
                $periods[] = $block;
                continue;
            }

            $content = is_array($rates['content'] ?? null) ? $rates['content'] : [];
            $contentTerminal = is_array($rates['content_terminal'] ?? null) ? $rates['content_terminal'] : [];
            $contentSales = is_array($rates['content_sales'] ?? null) ? $rates['content_sales'] : [];

            $shiftPay = self::shiftFixAccrual($operatorId, $scanStart, $scanEnd, $rates);
            foreach ($shiftPay['rows'] as $row) {
                $block['rows_shifts'][] = $row;
                $block['total'] += (float)$row['accrued'];
            }

            $sumsOp = self::sumsByType($operatorId, $scanStart, $scanEnd, 'ID_OPERATOR');
            foreach ($typeMap as $tid => $type) {
                $sum = (float)($sumsOp[$tid] ?? 0);
                $rate = (float)str_replace(',', '.', (string)($content[$tid] ?? $content[(string)$tid] ?? 0));
                $accrued = round($sum * $rate);
                if ($accrued == 0.0) {
                    continue;
                }
                $block['rows_proc'][] = [
                    'type' => (string)($type['type'] ?? ''),
                    'descr' => (string)($type['descr'] ?? ''),
                    'sum' => $sum,
                    'rate' => $rate,
                    'accrued' => $accrued,
                    'rate_kind' => 'fraction',
                ];
                $block['total'] += $accrued;
            }

            $sumsAdmin = self::sumsByType($operatorId, $scanStart, $scanEnd, 'ID_ADMIN');
            $countsAdmin = self::countsByType($operatorId, $scanStart, $scanEnd, 'ID_ADMIN');
            foreach ($typeMap as $tid => $type) {
                $rate = (float)str_replace(',', '.', (string)($contentTerminal[$tid] ?? $contentTerminal[(string)$tid] ?? 0));
                if ($rate == 2.0) {
                    continue; // oper skip marker
                }
                $sum = (float)($sumsAdmin[$tid] ?? 0);
                $num = (int)($countsAdmin[$tid] ?? 0);
                $accrued = round($sum * $rate);
                if ($accrued == 0.0) {
                    continue;
                }
                $block['rows_terminal'][] = [
                    'type' => (string)($type['type'] ?? ''),
                    'descr' => (string)($type['descr'] ?? '') . ($num > 0 ? ' (' . $num . ')' : ''),
                    'sum' => $sum,
                    'rate' => $rate,
                    'accrued' => $accrued,
                    'rate_kind' => 'fraction',
                ];
                $block['total'] += $accrued;
            }

            if (Database::tableExists('base_sales')) {
                $salesByType = self::salesByType($operatorId, $scanStart, $scanEnd);
                foreach ($salesByType as $tid => $sum) {
                    $rate = (float)str_replace(',', '.', (string)($contentSales[$tid] ?? $contentSales[(string)$tid] ?? 0));
                    $accrued = round((float)$sum * $rate);
                    if ($accrued == 0.0) {
                        continue;
                    }
                    $type = $typeMap[(int)$tid] ?? null;
                    $block['rows_sales'][] = [
                        'type' => $type ? (string)$type['type'] : ('#' . (int)$tid),
                        'descr' => $type ? (string)$type['descr'] : '',
                        'sum' => (float)$sum,
                        'rate' => $rate,
                        'accrued' => $accrued,
                        'rate_kind' => 'fraction',
                    ];
                    $block['total'] += $accrued;
                }
            }

            $audioPay = self::audioAccrualForPeriod($operatorId, $scanStart, $scanEnd, $rates);
            foreach ($audioPay['rows_audio'] as $row) {
                $block['rows_audio'][] = $row;
                $block['total'] += (float)$row['accrued'];
            }
            foreach ($audioPay['rows_scripts'] as $row) {
                $block['rows_scripts'][] = $row;
                $block['total'] += (float)$row['accrued'];
            }

            $grand += $block['total'];
            $periods[] = $block;
        }

        $rangeLabel = date('Y.m', $startTs) . ' – ' . date('Y.m', $endTs);
        return [
            'periods' => $periods,
            'grand_total' => $grand,
            'soft_notes' => $soft,
            'range_label' => $rangeLabel,
            'limit' => $effectiveLimit,
            'has_more' => $hasMore,
            'mode' => $mode,
        ];
    }

    /** @return array{start:int,end:int}|null */
    private static function procedureBounds(int $operatorId): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT MIN(DTSTART) AS mn, MAX(DTSTART) AS mx
             FROM base_procedures
             WHERE (ID_OPERATOR = ? OR ID_ADMIN = ?) AND DTSTART > 0'
        );
        $st->execute([$operatorId, $operatorId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)($row['mn'] ?? 0) < 1) {
            return null;
        }
        return ['start' => (int)$row['mn'], 'end' => (int)$row['mx']];
    }

    /**
     * @return list<array{start:int,end:int}>
     */
    private static function monthRange(int $startTs, int $endTs): array
    {
        $out = [];
        $y = (int)date('Y', $startTs);
        $m = (int)date('n', $startTs);
        $guard = 0;
        while ($guard++ < 240) {
            $s = mktime(0, 0, 0, $m, 1, $y);
            $e = mktime(23, 59, 59, $m, (int)date('t', $s ?: time()), $y);
            if ($s === false || $e === false) {
                break;
            }
            if ($e < $startTs) {
                $m++;
                if ($m > 12) {
                    $m = 1;
                    $y++;
                }
                continue;
            }
            if ($s > $endTs) {
                break;
            }
            $out[] = [
                'start' => max($s, $startTs),
                'end' => min($e, $endTs),
            ];
            $m++;
            if ($m > 12) {
                $m = 1;
                $y++;
            }
        }
        return array_reverse($out);
    }

    /**
     * @return array<int,float>
     */
    private static function sumsByType(int $operatorId, int $start, int $end, string $userCol): array
    {
        if (!in_array($userCol, ['ID_OPERATOR', 'ID_ADMIN'], true)) {
            return [];
        }
        $sql = "SELECT TYPE AS type_id, SUM(SUMM) AS summ
                FROM base_procedures
                WHERE `$userCol` = ? AND DTSTART > ? AND DTSTART < ?
                GROUP BY TYPE";
        $st = Database::pdo()->prepare($sql);
        $st->execute([$operatorId, $start, $end]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['type_id']] = (float)$r['summ'];
        }
        return $out;
    }

    /**
     * @return array<int,int>
     */
    private static function countsByType(int $operatorId, int $start, int $end, string $userCol): array
    {
        if (!in_array($userCol, ['ID_OPERATOR', 'ID_ADMIN'], true)) {
            return [];
        }
        $sql = "SELECT TYPE AS type_id, COUNT(ID) AS cnt
                FROM base_procedures
                WHERE `$userCol` = ? AND DTSTART > ? AND DTSTART < ?
                GROUP BY TYPE";
        $st = Database::pdo()->prepare($sql);
        $st->execute([$operatorId, $start, $end]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['type_id']] = (int)$r['cnt'];
        }
        return $out;
    }

    /**
     * Soft sales commission: STATUS=2 rows attributed to operator when columns exist.
     * @return array<int,float>
     */
    private static function salesByType(int $operatorId, int $start, int $end): array
    {
        if (!Database::tableExists('base_sales')) {
            return [];
        }
        $pdo = Database::pdo();
        $hasOp = Database::columnExists('base_sales', 'ID_OPERATOR');
        $hasDt = Database::columnExists('base_sales', 'DT');
        $hasStatus = Database::columnExists('base_sales', 'STATUS');
        $hasType = Database::columnExists('base_sales', 'TYPE') || Database::columnExists('base_sales', 'ID_TYPE');
        $hasSum = Database::columnExists('base_sales', 'SUMM') || Database::columnExists('base_sales', 'SUM');
        if (!$hasOp || !$hasDt || !$hasSum) {
            return [];
        }
        $sumCol = Database::columnExists('base_sales', 'SUMM') ? 'SUMM' : 'SUM';
        $typeCol = Database::columnExists('base_sales', 'TYPE') ? 'TYPE' : (Database::columnExists('base_sales', 'ID_TYPE') ? 'ID_TYPE' : null);
        if ($typeCol === null) {
            return [];
        }
        $sql = "SELECT `$typeCol` AS type_id, SUM(`$sumCol`) AS summ FROM base_sales
                WHERE ID_OPERATOR = ? AND DT > ? AND DT < ?";
        $params = [$operatorId, $start, $end];
        if ($hasStatus) {
            $sql .= ' AND STATUS = ?';
            $params[] = '2';
        }
        $sql .= " GROUP BY `$typeCol`";
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['type_id']] = (float)$r['summ'];
        }
        return $out;
    }

    /**
     * Day footer salary bits for operators (procedures commission + fix + terminal + soft audio).
     *
     * @param array<int,array{sums_by_type?:array<int,float>}> $operatorStats from ProceduresRepository::dayColumnStats
     * @return array<int,array{salary:float,salary_fix:float,salary_terminal:float,salary_sales:float,salary_audio:float,salary_scripts:float,total:float}>
     */
    public static function dayStaffPay(int $dayStart, int $dayEnd, array $operatorStats): array
    {
        $out = [];
        if ($dayEnd <= $dayStart || !Database::tableExists('base_procent_history')) {
            return $out;
        }
        $oids = [];
        $fpParts = [];
        foreach ($operatorStats as $oid => $op) {
            $oid = (int)$oid;
            if ($oid < 1) {
                continue;
            }
            $oids[] = $oid;
            $sums = is_array($op['sums_by_type'] ?? null) ? $op['sums_by_type'] : [];
            ksort($sums);
            $fpParts[] = $oid . ':' . json_encode($sums);
        }
        if ($oids === []) {
            return $out;
        }
        sort($oids);
        $cacheKey = $dayStart . '|' . $dayEnd . '|' . md5(implode(';', $fpParts));
        if (isset(self::$dayStaffPayCache[$cacheKey])) {
            return self::$dayStaffPayCache[$cacheKey];
        }

        $mid = $dayStart + 12 * 3600;
        $ratesMap = class_exists('RatesRepository', false)
            ? RatesRepository::latestForOperators($oids, $mid)
            : [];
        $adminSums = self::sumsByTypeMulti($oids, $dayStart, $dayEnd, 'ID_ADMIN');
        $shiftUnits = self::shiftUnitsMulti($oids, $dayStart, $dayEnd);
        $salesMap = Database::tableExists('base_sales')
            ? self::salesByTypeMulti($oids, $dayStart, $dayEnd)
            : [];

        foreach ($oids as $oid) {
            $op = $operatorStats[$oid] ?? $operatorStats[(string)$oid] ?? null;
            if (!is_array($op)) {
                continue;
            }
            $rates = $ratesMap[$oid] ?? null;
            if (!$rates) {
                continue;
            }
            $content = is_array($rates['content'] ?? null) ? $rates['content'] : [];
            $contentTerminal = is_array($rates['content_terminal'] ?? null) ? $rates['content_terminal'] : [];
            $contentSales = is_array($rates['content_sales'] ?? null) ? $rates['content_sales'] : [];
            $salary = 0.0;
            $sums = is_array($op['sums_by_type'] ?? null) ? $op['sums_by_type'] : [];
            foreach ($sums as $tid => $sum) {
                $rate = (float)str_replace(',', '.', (string)($content[$tid] ?? $content[(string)$tid] ?? 0));
                $salary += round((float)$sum * $rate);
            }
            $salaryFix = 0.0;
            $lossFix = (float)($rates['loss_fix'] ?? 0);
            $units = (float)($shiftUnits[$oid] ?? 0.0);
            if ($lossFix != 0.0 && $units > 0.0) {
                $salaryFix = round($units * $lossFix);
            }
            $salaryTerminal = 0.0;
            foreach (($adminSums[$oid] ?? []) as $tid => $sum) {
                $rate = (float)str_replace(',', '.', (string)($contentTerminal[$tid] ?? $contentTerminal[(string)$tid] ?? 0));
                if ($rate == 2.0) {
                    continue;
                }
                $salaryTerminal += round((float)$sum * $rate);
            }
            $salarySales = 0.0;
            foreach (($salesMap[$oid] ?? []) as $tid => $sum) {
                $rate = (float)str_replace(',', '.', (string)($contentSales[$tid] ?? $contentSales[(string)$tid] ?? 0));
                $salarySales += round((float)$sum * $rate);
            }
            $audioPay = self::audioAccrualForPeriod($oid, $dayStart, $dayEnd, $rates);
            $salaryAudio = (float)$audioPay['salary_audio'];
            $salaryScripts = (float)$audioPay['salary_scripts'];
            $total = $salary + $salaryFix + $salaryTerminal + $salarySales + $salaryAudio + $salaryScripts;
            if ($total <= 0 && $salaryFix <= 0) {
                continue;
            }
            $out[$oid] = [
                'salary' => $salary,
                'salary_fix' => $salaryFix,
                'salary_terminal' => $salaryTerminal,
                'salary_sales' => $salarySales,
                'salary_audio' => $salaryAudio,
                'salary_scripts' => $salaryScripts,
                'total' => $total,
            ];
        }
        self::$dayStaffPayCache[$cacheKey] = $out;
        return $out;
    }

    /**
     * @param list<int> $operatorIds
     * @return array<int,array<int,float>>
     */
    private static function sumsByTypeMulti(array $operatorIds, int $start, int $end, string $userCol): array
    {
        $out = [];
        if ($operatorIds === [] || !in_array($userCol, ['ID_OPERATOR', 'ID_ADMIN'], true)
            || !Database::tableExists('base_procedures')
        ) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($operatorIds), '?'));
        $params = array_merge($operatorIds, [$start, $end]);
        $st = Database::pdo()->prepare(
            "SELECT `$userCol` AS oid, TYPE AS type_id, SUM(SUMM) AS summ
             FROM base_procedures
             WHERE `$userCol` IN ($placeholders) AND DTSTART > ? AND DTSTART < ?
             GROUP BY `$userCol`, TYPE"
        );
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $oid = (int)($r['oid'] ?? 0);
            if ($oid < 1) {
                continue;
            }
            $out[$oid][(int)$r['type_id']] = (float)$r['summ'];
        }
        return $out;
    }

    /**
     * @param list<int> $operatorIds
     * @return array<int,float>
     */
    private static function shiftUnitsMulti(array $operatorIds, int $start, int $end): array
    {
        $out = [];
        // Half-open [start, end) — same as calendar day / dayColumnStats (end = next midnight).
        if ($operatorIds === [] || $end <= $start || !Database::tableExists('base_workweeks')) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($operatorIds), '?'));
        $params = array_merge($operatorIds, [$start, $end]);
        $st = Database::pdo()->prepare(
            "SELECT ID_OPERATOR AS oid, PRICE_COL AS price_col FROM base_workweeks
             WHERE ID_OPERATOR IN ($placeholders) AND DT >= ? AND DT < ?"
        );
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $oid = (int)($row['oid'] ?? 0);
            if ($oid < 1) {
                continue;
            }
            $pc = (int)($row['price_col'] ?? 0);
            if ($pc < 1) {
                continue;
            }
            $units = class_exists(WorkweeksRepository::class, false)
                ? WorkweeksRepository::shiftPayUnits($pc)
                : 1.0;
            if ($units > 0.0) {
                $out[$oid] = ($out[$oid] ?? 0.0) + $units;
            }
        }
        return $out;
    }

    /**
     * @param list<int> $operatorIds
     * @return array<int,array<int,float>>
     */
    private static function salesByTypeMulti(array $operatorIds, int $start, int $end): array
    {
        $out = [];
        if ($operatorIds === [] || !Database::tableExists('base_sales')) {
            return $out;
        }
        $pdo = Database::pdo();
        $hasOp = Database::columnExists('base_sales', 'ID_OPERATOR');
        $hasDt = Database::columnExists('base_sales', 'DT');
        $hasStatus = Database::columnExists('base_sales', 'STATUS');
        $hasSum = Database::columnExists('base_sales', 'SUMM') || Database::columnExists('base_sales', 'SUM');
        $sumCol = Database::columnExists('base_sales', 'SUMM') ? 'SUMM' : 'SUM';
        $typeCol = Database::columnExists('base_sales', 'TYPE')
            ? 'TYPE'
            : (Database::columnExists('base_sales', 'ID_TYPE') ? 'ID_TYPE' : null);
        if (!$hasOp || !$hasDt || !$hasSum || $typeCol === null) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($operatorIds), '?'));
        $sql = "SELECT ID_OPERATOR AS oid, `$typeCol` AS type_id, SUM(`$sumCol`) AS summ FROM base_sales
                WHERE ID_OPERATOR IN ($placeholders) AND DT > ? AND DT < ?";
        $params = array_merge($operatorIds, [$start, $end]);
        if ($hasStatus) {
            $sql .= ' AND STATUS = ?';
            $params[] = '2';
        }
        $sql .= " GROUP BY ID_OPERATOR, `$typeCol`";
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
        } catch (Throwable $e) {
            return $out;
        }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $oid = (int)($r['oid'] ?? 0);
            if ($oid < 1) {
                continue;
            }
            $out[$oid][(int)$r['type_id']] = (float)$r['summ'];
        }
        return $out;
    }

    /**
     * Fixed pay per shift: LOSS_FIX × shift units (I+II / composite window = 2 via workweeks).
     *
     * @param array<string,mixed> $rates
     * @return array{rows:list<array<string,mixed>>,salary_fix:float,units:float}
     */
    private static function shiftFixAccrual(int $operatorId, int $start, int $end, array $rates): array
    {
        $out = ['rows' => [], 'salary_fix' => 0.0, 'units' => 0.0];
        $rate = (float)($rates['loss_fix'] ?? 0);
        if ($operatorId < 1 || $rate == 0.0) {
            return $out;
        }
        $units = self::shiftUnits($operatorId, $start, $end);
        $out['units'] = $units;
        if ($units <= 0.0) {
            return $out;
        }
        $accrued = round($units * $rate);
        $out['salary_fix'] = $accrued;
        if ($accrued != 0.0) {
            $out['rows'][] = [
                'type' => t('procents.shifts.type'),
                'descr' => t('procents.shifts.worked'),
                'sum' => $units,
                'rate' => $rate,
                'accrued' => $accrued,
                'rate_kind' => 'absolute',
            ];
        }
        return $out;
    }

    /**
     * Shift pay units for [start, end): workweeks.shiftPayUnits (composite I+II → 2).
     */
    private static function shiftUnits(int $operatorId, int $start, int $end): float
    {
        if ($operatorId < 1 || $end <= $start || !Database::tableExists('base_workweeks')) {
            return 0.0;
        }
        $st = Database::pdo()->prepare(
            'SELECT PRICE_COL AS price_col FROM base_workweeks
             WHERE ID_OPERATOR = ? AND DT >= ? AND DT < ?'
        );
        $st->execute([$operatorId, $start, $end]);
        $units = 0.0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $pc = (int)($row['price_col'] ?? 0);
            if ($pc < 1) {
                continue;
            }
            $units += class_exists(WorkweeksRepository::class, false)
                ? WorkweeksRepository::shiftPayUnits($pc)
                : 1.0;
        }
        return $units;
    }

    /**
     * Audio hours × AUDIO_HOUR_RATE + collections SCORE × SCRIPT_SCORE_RATE.
     *
     * @param array<string,mixed> $rates
     * @return array{
     *   rows_audio:list<array<string,mixed>>,
     *   rows_scripts:list<array<string,mixed>>,
     *   salary_audio:float,
     *   salary_scripts:float
     * }
     */
    private static function audioAccrualForPeriod(int $adminId, int $start, int $end, array $rates): array
    {
        $empty = [
            'rows_audio' => [],
            'rows_scripts' => [],
            'salary_audio' => 0.0,
            'salary_scripts' => 0.0,
        ];
        if ($adminId < 1 || $end <= $start || !Database::tableExists('base_records')) {
            return $empty;
        }

        $hourRate = (float)($rates['audio_hour_rate'] ?? 0);
        $scriptRate = (float)($rates['script_score_rate'] ?? 0);
        // Skip DB when neither rate accrues (desk stubs / staff without audio pay).
        if ($hourRate == 0.0 && $scriptRate == 0.0) {
            return $empty;
        }

        $salaryAudio = 0.0;
        if ($hourRate != 0.0) {
            $stats = self::audioHoursStats($adminId, $start, $end);
            $hours = (float)$stats['hours'];
            if ($hours > 0.0) {
                $salaryAudio = round($hours * $hourRate);
                $descr = t('procents.audio.hours');
                if ($stats['avg_score'] !== null) {
                    $descr .= ' ®' . rtrim(rtrim(number_format((float)$stats['avg_score'], 1, '.', ''), '0'), '.');
                }
                $empty['rows_audio'][] = [
                    'type' => t('procents.audio.hours_type'),
                    'descr' => $descr,
                    'sum' => $hours,
                    'rate' => $hourRate,
                    'accrued' => $salaryAudio,
                    'rate_kind' => 'absolute',
                ];
            }
        }
        $empty['salary_audio'] = $salaryAudio;

        $salaryScripts = 0.0;
        if ($scriptRate != 0.0) {
            $scoreSum = self::scriptScoreSum($adminId, $start, $end);
            if ($scoreSum > 0.0) {
                $salaryScripts = round($scoreSum * $scriptRate);
                $empty['rows_scripts'][] = [
                    'type' => t('procents.audio.scripts_type'),
                    'descr' => t('procents.audio.scripts'),
                    'sum' => $scoreSum,
                    'rate' => $scriptRate,
                    'accrued' => $salaryScripts,
                    'rate_kind' => 'absolute',
                ];
            }
        }
        $empty['salary_scripts'] = $salaryScripts;

        return $empty;
    }

    /**
     * Accounted audio hours (OffRecord) + optional ®avg for display (OffRate / SCORE>2).
     *
     * @return array{hours:float,avg_score:float|null}
     */
    private static function audioHoursStats(int $adminId, int $start, int $end): array
    {
        $out = ['hours' => 0.0, 'avg_score' => null];
        if ($adminId < 1 || !Database::tableExists('base_records')) {
            return $out;
        }
        $bounds = self::audioDayBounds();
        $st = Database::pdo()->prepare(
            'SELECT PLAYTIME AS playtime, SCORE AS score, DT_RECORDED AS dt, DISABLED AS disabled
             FROM base_records
             WHERE ID_ADMIN = ? AND DT_RECORDED >= ? AND DT_RECORDED <= ?
               AND (DISABLED IS NULL OR DISABLED = 0 OR DISABLED = \'0\')'
        );
        $st->execute([$adminId, $start, $end]);
        $playSum = 0;
        $scoreW = 0.0;
        $scoreSec = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $dt = (int)($row['dt'] ?? 0);
            $play = max(0, (int)($row['playtime'] ?? 0));
            if ($dt < 1 || $play < 1) {
                continue;
            }
            if (!self::recordFullyInWindow($dt, $play, $bounds, 'OffRecord')) {
                continue;
            }
            $playSum += $play;
            $score = (float)($row['score'] ?? 0);
            if ($score < 0) {
                $score = 0.0;
            }
            $inRate = self::recordFullyInWindow($dt, $play, $bounds, 'OffRate');
            if ($inRate || $score > 2) {
                $scoreW += $score * $play;
                $scoreSec += $play;
            }
        }
        if ($playSum < 1) {
            return $out;
        }
        $out['hours'] = round($playSum / 3600, 1);
        if ($scoreSec > 0) {
            $out['avg_score'] = round($scoreW / $scoreSec, 1);
        }
        return $out;
    }

    private static function scriptScoreSum(int $adminId, int $start, int $end): float
    {
        if ($adminId < 1 || !Database::tableExists('base_records_collections')) {
            return 0.0;
        }
        if (!Database::columnExists('base_records_collections', 'SCORE')
            || !Database::columnExists('base_records_collections', 'ID_ADMIN')
            || !Database::columnExists('base_records_collections', 'DT_START')) {
            return 0.0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(SCORE), 0) FROM base_records_collections
             WHERE ID_ADMIN = ? AND DT_START >= ? AND DT_START <= ? AND SCORE > 0'
        );
        $st->execute([$adminId, $start, $end]);
        return (float)$st->fetchColumn();
    }

    /** @return array<string,int> */
    private static function audioDayBounds(): array
    {
        if (class_exists('AudioDayBounds', false)) {
            return AudioDayBounds::read();
        }
        return [
            'hourStartOffRate' => 9,
            'minStartOffRate' => 0,
            'hourEndOffRate' => 22,
            'minEndOffRate' => 0,
            'hourStartOffRecord' => 9,
            'minStartOffRecord' => 0,
            'hourEndOffRecord' => 22,
            'minEndOffRecord' => 0,
        ];
    }

    /**
     * @param array<string,int> $bounds
     * @param 'OffRate'|'OffRecord' $kind
     */
    private static function recordFullyInWindow(int $dt, int $playtime, array $bounds, string $kind): bool
    {
        if (class_exists('CalendarAggregates', false)) {
            return CalendarAggregates::isFullyInWindow($dt, $playtime, $bounds, $kind);
        }
        $dayKey = date('Y-m-d', $dt);
        $parts = explode('-', $dayKey);
        $y = (int)($parts[0] ?? 0);
        $m = (int)($parts[1] ?? 0);
        $d = (int)($parts[2] ?? 0);
        if ($y < 1 || $m < 1 || $d < 1) {
            return true;
        }
        $start = mktime(
            (int)$bounds['hourStart' . $kind],
            (int)$bounds['minStart' . $kind],
            0,
            $m,
            $d,
            $y
        );
        $end = mktime(
            (int)$bounds['hourEnd' . $kind],
            (int)$bounds['minEnd' . $kind],
            0,
            $m,
            $d,
            $y
        );
        if ($start === false || $end === false) {
            return true;
        }
        $dtEnd = $dt + max(0, $playtime);
        return $dt >= (int)$start && $dtEnd <= (int)$end;
    }
}
