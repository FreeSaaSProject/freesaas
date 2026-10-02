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
                'rows_proc' => [],
                'rows_terminal' => [],
                'rows_sales' => [],
                'total' => 0.0,
            ];
            if (!$rates) {
                $periods[] = $block;
                continue;
            }

            $content = is_array($rates['content'] ?? null) ? $rates['content'] : [];
            $contentTerminal = is_array($rates['content_terminal'] ?? null) ? $rates['content_terminal'] : [];
            $contentSales = is_array($rates['content_sales'] ?? null) ? $rates['content_sales'] : [];

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
                    ];
                    $block['total'] += $accrued;
                }
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
}
