<?php
declare(strict_types=1);

/**
 * Fund statement: opening, accrued, other inflows, spent, transfers, closing per fund and per group.
 * Operates on base_fund_movements only (DIRECTION * SUMM), so it matches FundMovementsRepository::balances().
 */
final class FundsStatement
{
    /**
     * Resolve a statement period from request-like input.
     *
     * @return array{gran:string,start:int,end:int,key:string,label:string,prev:string,next:string,this:string,is_current:bool}
     */
    public static function period(string $gran, string $anchor = ''): array
    {
        $gran = $gran === 'month' ? 'month' : 'week';
        $ts = $anchor !== '' ? (strtotime($anchor) ?: time()) : time();
        $mode = $gran === 'month' ? FundRulesRepository::PERIOD_MONTH : FundRulesRepository::PERIOD_WEEK;
        $b = FundAccrualRunner::periodBounds($mode, $ts);
        $now = FundAccrualRunner::periodBounds($mode, time());
        return [
            'gran' => $gran,
            'start' => $b['start'],
            'end' => $b['end'],
            'key' => $b['key'],
            'label' => $b['label'],
            'prev' => date('Y-m-d', $b['start'] - 86400),
            'next' => date('Y-m-d', $b['end']),
            'this' => date('Y-m-d', $now['start']),
            'is_current' => $b['start'] === $now['start'],
        ];
    }

    /**
     * Statement rows per leaf fund.
     *
     * @return array<int,array<string,float>> fund id => columns
     */
    private static function leafRows(int $start, int $end): array
    {
        if (!Database::tableExists('base_fund_movements')) {
            return [];
        }
        $acc = FundMovementsRepository::TYPE_ACCRUE;
        $cap = FundMovementsRepository::TYPE_CAPUSE;
        $tin = FundMovementsRepository::TYPE_TRANSFER_IN;
        $tout = FundMovementsRepository::TYPE_TRANSFER_OUT;
        $sql = "SELECT ID_FUND,
            COALESCE(SUM(CASE WHEN DT_AMORT_START < :s1 THEN DIRECTION * SUMM ELSE 0 END), 0) AS OPENING,
            COALESCE(SUM(CASE WHEN DT_AMORT_START >= :s2 AND DT_AMORT_START < :e1 AND ID_TYPE = $acc THEN SUMM ELSE 0 END), 0) AS ACCRUED,
            COALESCE(SUM(CASE WHEN DT_AMORT_START >= :s3 AND DT_AMORT_START < :e2 AND ID_TYPE = $tin THEN SUMM ELSE 0 END), 0) AS TRANSFER_IN,
            COALESCE(SUM(CASE WHEN DT_AMORT_START >= :s4 AND DT_AMORT_START < :e3 AND DIRECTION > 0 AND ID_TYPE NOT IN ($acc, $tin) THEN SUMM ELSE 0 END), 0) AS IN_OTHER,
            COALESCE(SUM(CASE WHEN DT_AMORT_START >= :s5 AND DT_AMORT_START < :e4 AND ID_TYPE = $cap THEN SUMM ELSE 0 END), 0) AS SPENT,
            COALESCE(SUM(CASE WHEN DT_AMORT_START >= :s6 AND DT_AMORT_START < :e5 AND ID_TYPE = $tout THEN SUMM ELSE 0 END), 0) AS TRANSFER_OUT,
            COALESCE(SUM(CASE WHEN DT_AMORT_START >= :s7 AND DT_AMORT_START < :e6 AND DIRECTION < 0 AND ID_TYPE NOT IN ($cap, $tout) THEN SUMM ELSE 0 END), 0) AS OUT_OTHER,
            COALESCE(SUM(CASE WHEN DT_AMORT_START < :e7 THEN DIRECTION * SUMM ELSE 0 END), 0) AS CLOSING
          FROM base_fund_movements GROUP BY ID_FUND";
        $st = Database::pdo()->prepare($sql);
        $params = [];
        for ($i = 1; $i <= 7; $i++) {
            $params['s' . $i] = $start;
            $params['e' . $i] = $end;
        }
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['ID_FUND']] = [
                'opening' => round((float)$r['OPENING'], 2),
                'accrued' => round((float)$r['ACCRUED'], 2),
                'transfer_in' => round((float)$r['TRANSFER_IN'], 2),
                'in_other' => round((float)$r['IN_OTHER'], 2),
                'spent' => round((float)$r['SPENT'], 2),
                'transfer_out' => round((float)$r['TRANSFER_OUT'], 2),
                'out_other' => round((float)$r['OUT_OTHER'], 2),
                'closing' => round((float)$r['CLOSING'], 2),
            ];
        }
        return $out;
    }

    /** @return array<string,float> */
    private static function zero(): array
    {
        return [
            'opening' => 0.0, 'accrued' => 0.0, 'transfer_in' => 0.0, 'in_other' => 0.0,
            'spent' => 0.0, 'transfer_out' => 0.0, 'out_other' => 0.0, 'closing' => 0.0,
        ];
    }

    /**
     * @param array<string,float> $a
     * @param array<string,float> $b
     * @return array<string,float>
     */
    private static function add(array $a, array $b): array
    {
        foreach ($a as $k => $v) {
            $a[$k] = round($v + (float)($b[$k] ?? 0.0), 2);
        }
        return $a;
    }

    /**
     * Build the statement tree for [start, end).
     *
     * @param array{fund_id?:int,fund_type?:int,hide_empty?:bool} $opts
     *   fund_type: 2 debit (default), 1 credit, 0 all. fund_id: a group id includes its children.
     * @return array{
     *   start:int,end:int,rows:list<array<string,mixed>>,totals:array<string,float>,negative:list<array<string,mixed>>
     * }
     */
    public static function build(int $start, int $end, array $opts = []): array
    {
        $fundType = array_key_exists('fund_type', $opts) ? (int)$opts['fund_type'] : 2;
        $onlyFund = (int)($opts['fund_id'] ?? 0);
        $hideEmpty = !empty($opts['hide_empty']);
        $funds = FundsRepository::allRows();
        $lines = self::leafRows($start, $end);
        $rulesByFund = FundRulesRepository::byFund();
        $counts = FundsRepository::childCounts();

        $make = static function (array $f, array $vals, bool $isGroup) use ($rulesByFund): array {
            $closing = (float)$vals['closing'];
            return [
                'id' => (int)$f['id'],
                'name' => (string)$f['name'],
                'pid' => (int)$f['pid'],
                'type' => (int)$f['type'],
                'is_group' => $isGroup,
                'opening' => $vals['opening'],
                'accrued' => $vals['accrued'],
                'transfer_in' => $vals['transfer_in'],
                'in_other' => $vals['in_other'],
                'spent' => $vals['spent'],
                'transfer_out' => $vals['transfer_out'],
                'out_other' => $vals['out_other'],
                'closing' => $vals['closing'],
                'negative' => $closing < -0.005,
                'rules' => $isGroup ? [] : ($rulesByFund[(int)$f['id']] ?? []),
                'children' => [],
            ];
        };
        $isEmpty = static function (array $v): bool {
            foreach ($v as $x) {
                if (abs((float)$x) > 0.004) {
                    return false;
                }
            }
            return true;
        };
        $typeOk = static function (array $f) use ($fundType): bool {
            return $fundType === 0 || (int)$f['type'] === $fundType;
        };

        $roots = [];
        $totals = self::zero();
        $negative = [];
        foreach ($funds as $f) {
            if ($f['pid'] > 0 && isset($funds[$f['pid']])) {
                continue;
            }
            $isGroup = isset($counts[$f['id']]);
            $kids = [];
            $sum = $isGroup ? self::zero() : ($lines[$f['id']] ?? self::zero());
            if ($isGroup) {
                // group's own movements are not allowed, children carry the numbers
                foreach ($funds as $c) {
                    if ($c['pid'] !== $f['id'] || !$typeOk($c)) {
                        continue;
                    }
                    $vals = $lines[$c['id']] ?? self::zero();
                    if ($hideEmpty && $isEmpty($vals)) {
                        continue;
                    }
                    $row = $make($c, $vals, false);
                    $kids[] = $row;
                    $sum = self::add($sum, $vals);
                    if ($row['negative']) {
                        $negative[] = ['id' => $row['id'], 'name' => $row['name'], 'closing' => $row['closing']];
                    }
                }
                if ($kids === []) {
                    continue;
                }
            } else {
                if (!$typeOk($f) || ($hideEmpty && $isEmpty($sum))) {
                    continue;
                }
            }
            $node = $make($f, $sum, $isGroup);
            $node['children'] = $kids;
            if ($onlyFund > 0 && $node['id'] !== $onlyFund && !in_array($onlyFund, array_column($kids, 'id'), true)) {
                continue;
            }
            if ($onlyFund > 0 && $isGroup && $node['id'] !== $onlyFund) {
                $node['children'] = array_values(array_filter($kids, static function (array $k) use ($onlyFund): bool {
                    return $k['id'] === $onlyFund;
                }));
            }
            if (!$isGroup && $node['negative']) {
                $negative[] = ['id' => $node['id'], 'name' => $node['name'], 'closing' => $node['closing']];
            }
            $roots[] = $node;
            $totals = self::add($totals, $sum);
        }
        usort($roots, static function (array $a, array $b): int {
            if ($a['is_group'] !== $b['is_group']) {
                return $a['is_group'] ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });
        return ['start' => $start, 'end' => $end, 'rows' => $roots, 'totals' => $totals, 'negative' => $negative];
    }

    /**
     * Overdrawn debit funds right now (closing balance < 0).
     *
     * @return list<array{id:int,name:string,balance:float}>
     */
    public static function overdrawn(): array
    {
        $out = [];
        $bal = FundMovementsRepository::balances();
        foreach (FundsRepository::allRows() as $f) {
            if ((int)$f['type'] !== 2 || FundsRepository::isGroup($f['id'])) {
                continue;
            }
            $b = round((float)($bal[$f['id']] ?? 0.0), 2);
            if ($b < -0.005) {
                $out[] = ['id' => $f['id'], 'name' => $f['name'], 'balance' => $b];
            }
        }
        return $out;
    }
}
