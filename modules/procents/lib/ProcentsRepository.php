<?php
declare(strict_types=1);

final class ProcentsRepository
{
    public static function canSeeOthers(): bool
    {
        return Auth::capability('CAN_SEE_SUM_OTHERS');
    }

    /**
     * Build month periods and accrual rows for an operator.
     *
     * @return array{
     *   periods:list<array<string,mixed>>,
     *   grand_total:float,
     *   soft_notes:list<string>,
     *   range_label:string
     * }
     */
    public static function report(int $operatorId, int $year = 0, int $month = 0): array
    {
        $soft = [];
        if ($operatorId < 1 || !Database::tableExists('base_procedures')) {
            return ['periods' => [], 'grand_total' => 0.0, 'soft_notes' => $soft, 'range_label' => ''];
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
            return ['periods' => [], 'grand_total' => 0.0, 'soft_notes' => $soft, 'range_label' => ''];
        }

        $startTs = (int)$bounds['start'];
        $endTs = (int)$bounds['end'];
        if ($year > 0 && $month > 0) {
            $startTs = mktime(0, 0, 0, $month, 1, $year) ?: $startTs;
            $endTs = mktime(23, 59, 59, $month, (int)date('t', $startTs), $year) ?: $endTs;
        } elseif ($year > 0) {
            $startTs = mktime(0, 0, 0, 1, 1, $year) ?: $startTs;
            $endTs = mktime(23, 59, 59, 12, 31, $year) ?: $endTs;
        }

        if (!self::canSeeOthers() && $endTs > time()) {
            $endTs = time();
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
        $mid = $dayStart + 12 * 3600;
        foreach ($operatorStats as $oid => $op) {
            $oid = (int)$oid;
            if ($oid < 1) {
                continue;
            }
            $rates = RatesRepository::latestForOperator($oid, $mid);
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
            $shiftPay = self::shiftFixAccrual($oid, $dayStart, $dayEnd, $rates);
            $salaryFix = (float)$shiftPay['salary_fix'];
            $salaryTerminal = 0.0;
            $sumsAdmin = self::sumsByType($oid, $dayStart, $dayEnd, 'ID_ADMIN');
            foreach ($sumsAdmin as $tid => $sum) {
                $rate = (float)str_replace(',', '.', (string)($contentTerminal[$tid] ?? $contentTerminal[(string)$tid] ?? 0));
                if ($rate == 2.0) {
                    continue;
                }
                $salaryTerminal += round((float)$sum * $rate);
            }
            $salarySales = 0.0;
            if (Database::tableExists('base_sales')) {
                foreach (self::salesByType($oid, $dayStart, $dayEnd) as $tid => $sum) {
                    $rate = (float)str_replace(',', '.', (string)($contentSales[$tid] ?? $contentSales[(string)$tid] ?? 0));
                    $salarySales += round((float)$sum * $rate);
                }
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
        return $out;
    }

    /**
     * Fixed pay per shift: LOSS_FIX × shift units (PRICE_COL=3 / I+II counts as 2).
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
     * Oper: PRICE_COL=3 (I+II) → 2 units; any other PRICE_COL>0 → 1 unit.
     */
    private static function shiftUnits(int $operatorId, int $start, int $end): float
    {
        if ($operatorId < 1 || $end < $start || !Database::tableExists('base_workweeks')) {
            return 0.0;
        }
        $st = Database::pdo()->prepare(
            'SELECT PRICE_COL AS price_col FROM base_workweeks
             WHERE ID_OPERATOR = ? AND DT >= ? AND DT <= ?'
        );
        $st->execute([$operatorId, $start, $end]);
        $units = 0.0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $pc = (int)($row['price_col'] ?? 0);
            if ($pc === 3) {
                $units += 2.0;
            } elseif ($pc > 0) {
                $units += 1.0;
            }
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
        $stats = self::audioHoursStats($adminId, $start, $end);
        $hours = (float)$stats['hours'];
        $salaryAudio = 0.0;
        if ($hours > 0.0 && $hourRate != 0.0) {
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
        $empty['salary_audio'] = $salaryAudio;

        $scoreSum = self::scriptScoreSum($adminId, $start, $end);
        $salaryScripts = 0.0;
        if ($scoreSum > 0.0 && $scriptRate != 0.0) {
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
