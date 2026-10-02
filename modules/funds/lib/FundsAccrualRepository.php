<?php
declare(strict_types=1);

/**
 * Fund accrual bots ported from oper show_funds CALCULATE / reprice2Real.
 */
final class FundsAccrualRepository
{
    /** System user used by oper bots for monthly accrual moves. */
    private const BOT_USER = 18;

    /**
     * @return array{os:float,fund:float,amort_months:int,amort_months_plus:int,month_payment:float,destroy:float}
     */
    public static function getFundBalance(int $fundId, int $fundType = 1, int $dtLim = 0): array
    {
        $empty = ['os' => 0.0, 'fund' => 0.0, 'amort_months' => 0, 'amort_months_plus' => 0, 'month_payment' => 0.0, 'destroy' => 0.0];
        if ($fundId < 1 || !Database::tableExists('base_fund_movements')) {
            return $empty;
        }
        $pdo = Database::pdo();
        $where = 'ID_FUND = ?';
        $params = [$fundId];
        if ($dtLim > 0) {
            $where .= ' AND DT_AMORT_START < ?';
            $params[] = $dtLim;
        }

        $st = $pdo->prepare("SELECT COALESCE(SUM(SUMM * DIRECTION), 0) FROM base_fund_movements WHERE $where");
        $st->execute($params);
        $osValue = round((float)$st->fetchColumn(), 2);
        if ($fundType > 1) {
            $osValue = 0.0;
        }

        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(SUMM), 0) FROM base_fund_movements WHERE $where AND DIRECTION > 0 AND ID_TYPE <> 4"
        );
        $st->execute($params);
        $fundIns = round((float)$st->fetchColumn(), 2);

        $st = $pdo->prepare("SELECT COALESCE(SUM(SUMM_OUT_FUND), 0) FROM base_fund_movements WHERE $where");
        $st->execute($params);
        $fundOuts = round((float)$st->fetchColumn(), 2);
        $fundValue = $fundIns - $fundOuts;

        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(SUMM_OUT_DESTROY), 0) FROM base_fund_movements WHERE $where AND ID_TYPE = 5"
        );
        $st->execute($params);
        $destroy = round((float)$st->fetchColumn(), 2);
        $fundValue -= $destroy;

        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(AMORT_MONTHS), 0) FROM base_fund_movements WHERE $where AND AMORT_MONTHS > 0"
        );
        $st->execute($params);
        $amortPlus = (int)$st->fetchColumn();

        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(AMORT_MONTHS), 0) FROM base_fund_movements WHERE $where AND AMORT_MONTHS < 0"
        );
        $st->execute($params);
        $amortMinus = (int)$st->fetchColumn();
        $amortMonths = $amortPlus + $amortMinus;
        $monthPayment = $amortMonths !== 0 ? round($osValue / $amortMonths, 2) : 0.0;

        return [
            'os' => $osValue,
            'fund' => $fundValue,
            'amort_months' => $amortMonths,
            'amort_months_plus' => $amortPlus,
            'month_payment' => $monthPayment,
            'destroy' => $destroy,
        ];
    }

    /**
     * Monthly amort / USN accrual into funds (oper us[do]=CALCULATE).
     *
     * @return array{ok:bool,message:string,rows:list<array<string,mixed>>}
     */
    public static function calculatePayments(): array
    {
        if (!Database::tableExists('base_funds') || !Database::tableExists('base_fund_movements')) {
            return ['ok' => false, 'message' => t('funds.accrual.err.tables'), 'rows' => []];
        }
        $pdo = Database::pdo();
        $funds = $pdo->query('SELECT ID, NAME, TYPE FROM base_funds WHERE PID > 0 ORDER BY NAME ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $monthStart = mktime(0, 0, 0, (int)date('n'), 1) ?: time();
        $monthEnd = mktime(0, 0, 0, (int)date('n') + 1, 0) ?: time();
        $now = time();
        $bot = self::BOT_USER;
        $rows = [];
        $total = 0.0;

        foreach ($funds as $fund) {
            $fundId = (int)$fund['ID'];
            $fundType = (int)$fund['TYPE'];
            $name = (string)$fund['NAME'];
            $bal = self::getFundBalance($fundId, $fundType);
            $os = abs((float)$bal['os']);
            $fundVal = abs((float)$bal['fund']);
            $balance = $os + $fundVal;
            $amortPayment = (float)$bal['month_payment'];
            $status = '';

            if ($fundType > 1) {
                $status .= t('funds.accrual.debit');
                if (mb_stripos($name, ' УСН') !== false || mb_stripos($name, 'УСН') !== false) {
                    $usn = self::calcUsnMonthPayment();
                    $status .= ' ' . t('funds.accrual.usn_pay', ['sum' => number_format($usn, 2, '.', ' ')]);
                    $stDel = $pdo->prepare(
                        'DELETE FROM base_fund_movements
                         WHERE ID_FUND = ? AND ID_USER_CREATE = ? AND DIRECTION > 0
                           AND DT_AMORT_START > ? AND DT_AMORT_START < ?'
                    );
                    $stDel->execute([$fundId, $bot, $monthStart, $monthEnd]);
                    $bal = self::getFundBalance($fundId, $fundType);
                    $balance = abs((float)$bal['os']) + abs((float)$bal['fund']);
                    if ($usn > 0) {
                        self::insertRefund(
                            $fundId,
                            $fundType,
                            $usn,
                            t('funds.accrual.usn_name', [
                                'name' => $name,
                                'date' => date('d.m.Y'),
                                'balance' => number_format($balance, 2, '.', ' '),
                                'pay' => number_format($usn, 2, '.', ' '),
                            ]),
                            $bot,
                            $now
                        );
                        $status .= ' ' . t('funds.accrual.added');
                        $amortPayment = $usn;
                    }
                }
            } else {
                if ($amortPayment != 0.0) {
                    $stC = $pdo->prepare(
                        'SELECT COUNT(*) FROM base_fund_movements
                         WHERE ID_FUND = ? AND ID_USER_CREATE = ? AND DIRECTION > 0
                           AND DT_AMORT_START > ? AND DT_AMORT_START < ?'
                    );
                    $stC->execute([$fundId, $bot, $monthStart, $monthEnd]);
                    if ((int)$stC->fetchColumn() > 0) {
                        $status .= t('funds.accrual.exists');
                    } else {
                        self::insertRefund(
                            $fundId,
                            $fundType,
                            abs($amortPayment),
                            t('funds.accrual.amort_name', [
                                'name' => $name,
                                'os' => number_format($os, 2, '.', ' '),
                                'pay' => number_format(abs($amortPayment), 2, '.', ' '),
                            ]),
                            $bot,
                            $now,
                            -1
                        );
                        $status .= t('funds.accrual.added');
                    }
                } else {
                    $status .= t('funds.accrual.fully_amortized');
                }
            }

            $total += $amortPayment;
            $rows[] = [
                'name' => $name,
                'type' => FundsRepository::typeLabel($fundType),
                'balance' => $balance,
                'os' => $os,
                'months_plus' => (int)$bal['amort_months_plus'],
                'months_left' => (int)$bal['amort_months'],
                'payment' => $amortPayment,
                'status' => $status,
            ];
        }

        return [
            'ok' => true,
            'message' => t('funds.accrual.done', ['total' => number_format($total, 2, '.', ' ')]),
            'rows' => $rows,
        ];
    }

    /**
     * Align fund book value to REAL_PRICE via REVAL/DEVAL/DESTROY (oper reprice2Real).
     *
     * @return array{ok:bool,message:string,created:int}
     */
    public static function repriceToReal(): array
    {
        if (!Database::tableExists('base_funds') || !Database::tableExists('base_fund_movements')) {
            return ['ok' => false, 'message' => t('funds.accrual.err.tables'), 'created' => 0];
        }
        $pdo = Database::pdo();
        $funds = $pdo->query(
            'SELECT ID, NAME, TYPE, REAL_PRICE FROM base_funds WHERE PID > 0 AND REAL_PRICE > 0 ORDER BY NAME ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $created = 0;
        $now = time();
        $user = Auth::userId();

        foreach ($funds as $fund) {
            $fundId = (int)$fund['ID'];
            $fundType = (int)$fund['TYPE'];
            $real = (float)$fund['REAL_PRICE'];
            $bal = self::getFundBalance($fundId, $fundType);
            $os = abs((float)$bal['os']);
            $fundVal = abs((float)$bal['fund']);
            $balance = $os + $fundVal;
            $diff = $balance - $real;
            if (abs($diff) < 0.01) {
                continue;
            }

            $typeId = 0;
            $summ = 0.0;
            $summDestroy = 0.0;
            $direction = 1;
            if ($diff > 0) {
                // Book > real → DEVAL or DESTROY
                if ($diff > $os && $diff < $balance) {
                    $typeId = FundMovementsRepository::TYPE_DESTROY;
                    $summ = $diff;
                    $summDestroy = $diff;
                    $direction = 1;
                } else {
                    $typeId = FundMovementsRepository::TYPE_DEVAL;
                    $summ = $diff;
                    $direction = 1;
                }
            } else {
                $typeId = FundMovementsRepository::TYPE_REVAL;
                $summ = abs($diff);
                $direction = -1;
            }
            if ($typeId < 1 || $summ <= 0) {
                continue;
            }

            $st = $pdo->prepare(
                'INSERT INTO base_fund_movements
                 (ID_FUND, ID_TYPE, ID_FUND_TYPE, ID_USER_CREATE, DIRECTION, SUMM, SUMM_OUT_DESTROY,
                  SUMM_OUT_INFL, NAME, DESCR, DT_AMORT_START, DT_CREATE, AMORT_MONTHS)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
            );
            $infl = $typeId === FundMovementsRepository::TYPE_REVAL ? $summ : 0.0;
            $st->execute([
                $fundId,
                $typeId,
                $fundType,
                $user,
                $direction,
                $summ,
                $summDestroy,
                $infl,
                t('funds.accrual.reprice_name', [
                    'name' => (string)$fund['NAME'],
                    'from' => number_format($balance, 2, '.', ' '),
                    'to' => number_format($real, 2, '.', ' '),
                ]),
                '',
                $now,
                $now,
            ]);
            $created++;
        }

        return [
            'ok' => true,
            'message' => t('funds.accrual.reprice_done', ['n' => $created]),
            'created' => $created,
        ];
    }

    private static function calcUsnMonthPayment(): float
    {
        if (!Database::tableExists('base_account_moves') || !Database::tableExists('base_account_move_types')) {
            return 0.0;
        }
        $pdo = Database::pdo();
        $payers = [];
        foreach ($pdo->query(
            'SELECT ID FROM base_account_move_types WHERE DIRECTION > 0 AND PID > 0 AND IS_USNO_PAYING > 0'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $payers[] = (int)$id;
        }
        $discounters = [];
        foreach ($pdo->query(
            'SELECT ID FROM base_account_move_types WHERE DIRECTION < 0 AND PID > 0 AND IS_USNO_DISCOUNTING > 0'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $discounters[] = (int)$id;
        }
        if ($payers === []) {
            return 0.0;
        }
        $monthStart = mktime(0, 0, 0, (int)date('n'), 1) ?: 0;
        $monthEnd = mktime(0, 0, 0, (int)date('n') + 1, 0) ?: 0;
        $ph = implode(',', array_fill(0, count($payers), '?'));
        $params = array_merge($payers, [$monthStart, $monthEnd]);
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(SUMM), 0) FROM base_account_moves
             WHERE ID_TYPE IN ($ph) AND DT > ? AND DT < ? AND DELETED = 0"
        );
        $st->execute($params);
        $cashIn = (float)$st->fetchColumn();
        $usn = $cashIn * 0.06;
        $maxDiscount = $usn * 0.5;
        $discount = 0.0;
        if ($discounters !== []) {
            $ph2 = implode(',', array_fill(0, count($discounters), '?'));
            $params2 = array_merge($discounters, [$monthStart, $monthEnd]);
            $st2 = $pdo->prepare(
                "SELECT COALESCE(SUM(SUMM), 0) FROM base_account_moves
                 WHERE ID_TYPE IN ($ph2) AND DT > ? AND DT < ? AND DELETED = 0"
            );
            $st2->execute($params2);
            $discount = (float)$st2->fetchColumn();
            if ($discount > $maxDiscount) {
                $discount = $maxDiscount;
            }
        }
        return round($usn - $discount, 2);
    }

    private static function insertRefund(
        int $fundId,
        int $fundType,
        float $summ,
        string $name,
        int $userId,
        int $now,
        int $amortMonths = 0
    ): void {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_fund_movements
             (ID_FUND, ID_TYPE, ID_FUND_TYPE, ID_USER_CREATE, DIRECTION, SUMM, NAME,
              DT_AMORT_START, DT_CREATE, AMORT_MONTHS)
             VALUES (?, 2, ?, ?, 1, ?, ?, ?, ?, ?)'
        );
        $st->execute([$fundId, $fundType, $userId, abs($summ), $name, $now, $now, $amortMonths]);
    }
}
