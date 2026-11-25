<?php
declare(strict_types=1);

/**
 * Applies accrual rules (base_fund_rules) to periods: one ACCRUE movement per (rule, period key).
 * Idempotent: re-running recalculates the amount in place, never duplicates.
 */
final class FundAccrualRunner
{
    /** Default lookback for cron: late-entered DPS still recalc recent periods. */
    public const DEFAULT_LOOKBACK_DAYS = 40;

    /** ISO week key (2026-W47) or month key (2026-11) for a timestamp. */
    public static function periodKey(int $period, int $ts): string
    {
        return $period === FundRulesRepository::PERIOD_MONTH ? date('Y-m', $ts) : date('o-\WW', $ts);
    }

    /**
     * @return array{start:int,end:int,key:string,label:string} half-open [start, end)
     */
    public static function periodBounds(int $period, int $ts): array
    {
        if ($period === FundRulesRepository::PERIOD_MONTH) {
            $start = mktime(0, 0, 0, (int)date('n', $ts), 1, (int)date('Y', $ts)) ?: $ts;
            $end = mktime(0, 0, 0, (int)date('n', $ts) + 1, 1, (int)date('Y', $ts)) ?: ($start + 86400 * 31);
            return ['start' => $start, 'end' => $end, 'key' => date('Y-m', $start), 'label' => date('m.Y', $start)];
        }
        $dow = (int)date('N', $ts); // 1 = Monday
        $start = mktime(0, 0, 0, (int)date('n', $ts), (int)date('j', $ts) - ($dow - 1), (int)date('Y', $ts)) ?: $ts;
        $end = mktime(0, 0, 0, (int)date('n', $start), (int)date('j', $start) + 7, (int)date('Y', $start)) ?: ($start + 7 * 86400);
        return [
            'start' => $start,
            'end' => $end,
            'key' => date('o-\WW', $start),
            'label' => date('d.m', $start) . ' — ' . date('d.m.Y', $end - 1),
        ];
    }

    /**
     * Periods of a rule overlapping [from, to].
     *
     * @param array<string,mixed> $rule
     * @return list<array{start:int,end:int,key:string,label:string}>
     */
    public static function periodsFor(array $rule, int $from, int $to): array
    {
        $period = $rule['period'] === 'month' ? FundRulesRepository::PERIOD_MONTH : FundRulesRepository::PERIOD_WEEK;
        $out = [];
        $cursor = $from;
        $guard = 0;
        while ($cursor <= $to && $guard++ < 400) {
            $b = self::periodBounds($period, $cursor);
            $dtFrom = (int)$rule['dt_from'];
            $dtTo = (int)$rule['dt_to'];
            $inside = ($dtFrom < 1 || $b['end'] > $dtFrom) && ($dtTo < 1 || $b['start'] <= $dtTo);
            if ($inside) {
                $out[$b['key']] = $b;
            }
            $cursor = $b['end'];
        }
        return array_values($out);
    }

    /**
     * Ledger income in [start, end): operating income DPS (ODDS class 1, not excluded from P&L, not transfers),
     * or only the selected move types (group ids include their children).
     *
     * @param array<string,mixed> $rule
     */
    public static function baseAmount(array $rule, int $start, int $end): float
    {
        if (!Database::tableExists('base_account_moves') || !Database::tableExists('base_account_move_types')) {
            return 0.0;
        }
        $params = [$start, $end];
        $where = 'm.DELETED = 0 AND m.DIRECTION > 0 AND m.ID_MOVEMENT_LINKED = 0 AND m.DT >= ? AND m.DT < ?';
        if ($rule['base_mode'] === 'types') {
            $ids = array_values(array_unique(array_map('intval', (array)$rule['base_types'])));
            if ($ids === []) {
                return 0.0;
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $where .= " AND (t.ID IN ($ph) OR t.PID IN ($ph))";
            $params = array_merge($params, $ids, $ids);
        } else {
            $where .= ' AND t.ID_TYPE = 1 AND t.EXCLUDE_PL = 0';
        }
        $st = Database::pdo()->prepare(
            "SELECT COALESCE(SUM(m.SUMM), 0) FROM base_account_moves m
             INNER JOIN base_account_move_types t ON t.ID = m.ID_TYPE
             WHERE $where"
        );
        $st->execute($params);
        return round((float)$st->fetchColumn(), 2);
    }

    /** @return array<string,mixed>|null raw movement row of this rule+period */
    private static function existing(int $ruleId, string $key): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT ID, SUMM, ID_FUND FROM base_fund_movements
             WHERE RULE_ID = ? AND PERIOD_KEY = ? AND ID_TYPE = ? ORDER BY ID ASC LIMIT 1'
        );
        $st->execute([$ruleId, $key, FundMovementsRepository::TYPE_ACCRUE]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * @param array{from?:int,to?:int,rule_id?:int,dry_run?:bool,include_open?:bool,now?:int} $opts
     *   from/to: unix range of periods to (re)calculate; default now-40d .. now.
     *   include_open: also accrue the not yet finished current period (provisional).
     * @return array{ok:bool,dry_run:bool,from:int,to:int,created:int,updated:int,unchanged:int,deleted:int,skipped:int,rows:list<array<string,mixed>>}
     */
    public static function run(array $opts = []): array
    {
        $now = (int)($opts['now'] ?? time());
        $to = (int)($opts['to'] ?? $now);
        $from = (int)($opts['from'] ?? ($to - self::DEFAULT_LOOKBACK_DAYS * 86400));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $dry = !empty($opts['dry_run']);
        $includeOpen = !empty($opts['include_open']);
        $ruleId = (int)($opts['rule_id'] ?? 0);
        $res = [
            'ok' => true, 'dry_run' => $dry, 'from' => $from, 'to' => $to,
            'created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0, 'skipped' => 0, 'rows' => [],
        ];
        if (!FundRulesRepository::tableReady() || !Database::tableExists('base_fund_movements')) {
            $res['ok'] = false;
            return $res;
        }
        $pdo = Database::pdo();
        $ownTx = !$dry && !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            foreach (FundRulesRepository::list(['enabled' => 1]) as $rule) {
                if ($ruleId > 0 && (int)$rule['id'] !== $ruleId) {
                    continue;
                }
                foreach (self::periodsFor($rule, $from, $to) as $p) {
                    $res['rows'][] = self::applyPeriod($rule, $p, $now, $includeOpen, $dry, $res);
                }
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $res;
    }

    /**
     * @param array<string,mixed> $rule
     * @param array{start:int,end:int,key:string,label:string} $p
     * @param array<string,mixed> $res counters, updated by reference
     * @return array<string,mixed>
     */
    private static function applyPeriod(array $rule, array $p, int $now, bool $includeOpen, bool $dry, array &$res): array
    {
        $row = [
            'rule_id' => (int)$rule['id'],
            'fund_id' => (int)$rule['fund_id'],
            'fund_name' => (string)$rule['fund_name'],
            'period_key' => $p['key'],
            'period_label' => $p['label'],
            'base' => 0.0,
            'amount' => 0.0,
            'action' => 'skipped_open',
            'movement_id' => 0,
        ];
        $open = $p['end'] > $now;
        if ($open && !$includeOpen) {
            $res['skipped']++;
            return $row;
        }
        $base = $rule['kind'] === 'percent' ? self::baseAmount($rule, $p['start'], $p['end']) : 0.0;
        $amount = $rule['kind'] === 'percent'
            ? round($base * (float)$rule['percent'] / 100, 2)
            : round((float)$rule['fixed_sum'], 2);
        $row['base'] = $base;
        $row['amount'] = $amount;

        $ex = self::existing((int)$rule['id'], $p['key']);
        $fmId = $ex !== null ? (int)$ex['ID'] : 0;
        $row['movement_id'] = $fmId;

        if ($amount <= 0) {
            if ($ex !== null) {
                if (!$dry) {
                    FundMovementsRepository::deleteByIds([$fmId]);
                }
                $row['action'] = 'deleted';
                $res['deleted']++;
            } else {
                $row['action'] = 'skipped_zero';
                $res['skipped']++;
            }
            return $row;
        }
        if ($ex !== null && round((float)$ex['SUMM'], 2) === $amount && (int)$ex['ID_FUND'] === (int)$rule['fund_id']) {
            $row['action'] = 'unchanged';
            $res['unchanged']++;
            return $row;
        }

        $dt = $open ? min($p['end'] - 1, $now) : $p['end'] - 1;
        $name = mb_substr(
            t('funds.accrue.name', [
                'rule' => (string)($rule['name'] !== '' ? $rule['name'] : ('#' . $rule['id'])),
                'period' => $p['label'],
            ]),
            0,
            250
        );
        $descr = $rule['kind'] === 'percent'
            ? t('funds.accrue.descr_percent', [
                'percent' => rtrim(rtrim(number_format((float)$rule['percent'], 3, '.', ''), '0'), '.'),
                'base' => FundMovementsRepository::fmt($base),
            ])
            : t('funds.accrue.descr_fixed');
        $data = [
            'fund_id' => (int)$rule['fund_id'],
            'type_id' => FundMovementsRepository::TYPE_ACCRUE,
            'direction' => 1,
            'summ' => $amount,
            'summ_out_cap' => 0.0,
            'summ_out_fund' => 0.0,
            'summ_out_infl' => 0.0,
            'summ_out_destroy' => 0.0,
            'name' => $name,
            'descr' => $descr,
            'dt_amort_start' => $dt,
            'amort_months' => 0,
            'dt_create' => time(),
            'user_create' => Auth::userId(),
            'rule_id' => (int)$rule['id'],
            'period_key' => $p['key'],
            'src' => FundMovementsRepository::SRC_RULE,
        ];
        if ($ex !== null) {
            if (!$dry) {
                FundMovementsRepository::update($fmId, $data);
            }
            $row['action'] = 'updated';
            $res['updated']++;
        } else {
            if (!$dry) {
                $row['movement_id'] = FundMovementsRepository::create($data);
            }
            $row['action'] = 'created';
            $res['created']++;
        }
        return $row;
    }
}
