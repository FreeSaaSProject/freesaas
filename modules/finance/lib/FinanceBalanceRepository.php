<?php
declare(strict_types=1);

/**
 * Port of oper show_balance5 (no inflation / INFL_CAP).
 */
final class FinanceBalanceRepository
{
    /**
     * @return array{
     *   year:int, years:list<int>, y_start:int, y_end:int,
     *   period_labels:array<int,string>, period_years:array<int,int>,
     *   odds:array<string,array<int,float|string>>,
     *   opiu:array<string,array<int,float|string>>,
     *   accounts:array<string,array<int,float|string>>,
     *   balance:array<string,array<int,float|string>>,
     *   kanal:array<string,array<int,float|string>>,
     *   funds:?array<string,array<int,float|string>>,
     *   meta:array<string,array<string,array{hl?:bool,group?:int}>>,
     *   link_groups:array<int,list<string>>,
     *   select:int,
     *   empty:bool
     * }
     */
    public static function build(int $year = 0, int $select = 0, array $opts = []): array
    {
        $yoy = !empty($opts['yoy']);
        $yoyMode = 'ytd';
        if ($yoy && isset($opts['yoy_mode'])) {
            $ym = (string)$opts['yoy_mode'];
            if (in_array($ym, ['month', 'ytd', 'rolling'], true)) {
                $yoyMode = $ym;
            }
        }
        $yoyAsOf = $yoy ? FinanceYoy::parseAsOf($opts['yoy_as_of'] ?? time()) : 0;
        $yoyNavYear = $yoy ? (int)($opts['yoy_nav_year'] ?? 0) : 0;
        $yoyChartView = 'compare';
        if ($yoy && isset($opts['yoy_chart_view']) && (string)$opts['yoy_chart_view'] === 'years') {
            $yoyChartView = 'years';
        }
        $yoyYears = 4;
        if ($yoy && isset($opts['yoy_years'])) {
            $yoyYears = (int)$opts['yoy_years'];
            if ($yoyYears < 2) {
                $yoyYears = 2;
            }
            if ($yoyYears > 8) {
                $yoyYears = 8;
            }
        }

        $empty = self::emptyResult($year, $select);
        if ($yoy) {
            $empty['yoy'] = true;
            $empty['yoy_mode'] = $yoyMode;
            $empty['yoy_as_of'] = date('Y-m-d', $yoyAsOf);
            $empty['show_sum'] = false;
            $empty['yoy_chart_view'] = $yoyChartView;
            $empty['yoy_years'] = $yoyYears;
            $empty['yoy_chart_payload'] = null;
            $empty['yoy_years_payload'] = null;
        }
        if (!Database::tableExists('base_account_moves') || !Database::tableExists('base_accounts')) {
            return $empty;
        }

        $pdo = Database::pdo();
        $range = $pdo->query(
            'SELECT MIN(DT) AS mn, MAX(DT) AS mx FROM base_account_moves WHERE DELETED = 0 AND UNCONFIRMED = 0'
        )->fetch(PDO::FETCH_ASSOC);
        $dtStart = (int)($range['mn'] ?? 0);
        $dtEnd = (int)($range['mx'] ?? 0);
        if ($dtStart < 1 || $dtEnd < 1) {
            return $empty;
        }

        $yStart = (int)date('Y', $dtStart);
        $yEnd = (int)date('Y', $dtEnd);
        $mEnd = (int)date('n', $dtEnd);
        $years = [];
        for ($y = $yStart; $y <= $yEnd; $y++) {
            $years[] = $y;
        }
        if ($year > 0 && ($year < $yStart || $year > $yEnd)) {
            $year = 0;
        }

        $yoyGrid = null;
        $yoyChartPairs = [];
        $yoyChartYearMonthIdx = [];
        if ($yoy) {
            // YoY uses its own monthly window grid (opening + A/B buckets).
            $year = 0;
            $yoyGrid = FinanceYoy::buildPeriodGrid(
                $yoyMode,
                $yoyAsOf,
                $yoyNavYear,
                $dtStart,
                $dtEnd,
                $yStart,
                $yEnd,
                $mEnd
            );
            $yoyMode = $yoyGrid['mode'];
            $yoyNavYear = (int)$yoyGrid['nav_year'];
            $allPeriods = $yoyGrid['periods'];
            $yoyChartPairs = $yoyGrid['chart_pairs'] ?? [];
            $nextN = (int)($yoyGrid['next_n'] ?? (count($allPeriods) + 1));

            if ($yoyChartView === 'years') {
                $asOfY = (int)date('Y', $yoyAsOf);
                $asOfM = (int)date('n', $yoyAsOf);
                $asOfD = (int)date('j', $yoyAsOf);
                $mStart = (int)date('n', $dtStart);
                $appended = FinanceYoy::appendMultiYearChartPeriods(
                    $allPeriods,
                    $nextN,
                    $yoyYears,
                    $asOfY,
                    $asOfM,
                    $asOfD,
                    $yStart,
                    $yEnd,
                    $mEnd > 0 ? $mEnd : $mStart
                );
                $yoyChartYearMonthIdx = $appended['year_month_idx'];
            }

            $displayNs = [];
            foreach ($allPeriods as $n => $p) {
                if (empty($p['chart_only'])) {
                    $displayNs[] = $n;
                }
            }
        } else {
            // When drilling into a year, build monthly buckets from data start through that year
            // so cumulative balance / funds / cash carry forward (oper nStart/nEnd pattern).
            $allPeriods = self::buildPeriods($year, $yStart, $yEnd, $mEnd);
            $displayNs = [];
            foreach ($allPeriods as $n => $p) {
                if ($year === 0 || (int)$p['Y'] === $year) {
                    $displayNs[] = $n;
                }
            }
        }
        if ($allPeriods === [] || $displayNs === []) {
            return $empty;
        }

        $accounts = self::loadAccounts();
        $typesIns = self::loadMoveTypes(1);
        $typesOuts = self::loadMoveTypes(-1);
        $hasFunds = ModuleManager::isEnabled('funds')
            && Database::tableExists('base_funds')
            && Database::tableExists('base_fund_movements');
        $funds = $hasFunds ? self::loadFunds() : ['leaf' => [], 'pids' => []];
        $fundMoves = $hasFunds ? self::loadFundMovements() : [];

        $oddsAmorted = [];
        $oddsNonAmorted = [];
        $oddsAll = [];
        $accountsSrc = [];
        $loanFrom = [];
        $loanTo = [];
        $anyLoan = false;
        $amortTypes = [];
        $fundDebKred = [];
        $promisedPays = [];
        $promisedIncomes = [];
        $fundMovesWithDds = [];

        foreach (array_keys($allPeriods) as $n) {
            $loanFrom[$n] = 0.0;
            $loanTo[$n] = 0.0;
        }

        $st = $pdo->query(
            'SELECT ID, DT, ID_TYPE, ID_ACCOUNT, ID_MOVEMENT_LINKED, ID_FUND_MOVEMENT,
                    DIRECTION, SUMM, DT_PROMISED
             FROM base_account_moves
             WHERE DELETED = 0 AND UNCONFIRMED = 0
             ORDER BY DT ASC'
        );
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $dt = (int)$row['DT'];
            if ($year > 0 && (int)date('Y', $dt) > $year) {
                break;
            }
            $typeId = (int)$row['ID_TYPE'];
            $accountId = (int)$row['ID_ACCOUNT'];
            $linked = (int)$row['ID_MOVEMENT_LINKED'];
            $fundMoveId = (int)$row['ID_FUND_MOVEMENT'];
            $direction = (int)$row['DIRECTION'];
            $summ = round($direction * (float)$row['SUMM'], 2);
            $dtPromised = (int)$row['DT_PROMISED'];
            $accType = (int)($accounts[$accountId]['id_type'] ?? 2);
            $bamId = (int)$row['ID'];

            foreach ($allPeriods as $n => $period) {
                if (!isset($oddsAmorted[$typeId][$n])) {
                    $oddsAmorted[$typeId][$n] = 0.0;
                }
                if (!isset($oddsNonAmorted[$typeId][$n])) {
                    $oddsNonAmorted[$typeId][$n] = 0.0;
                }
                if (!isset($oddsAll[$typeId][$n])) {
                    $oddsAll[$typeId][$n] = 0.0;
                }
                if (!isset($accountsSrc[$accountId][$n])) {
                    $accountsSrc[$accountId][$n] = 0.0;
                }

                if ($dt < $period['start'] || $dt >= $period['end']) {
                    continue;
                }

                if ($linked < 1) {
                    $oddsAll[$typeId][$n] += $summ;
                    if ($fundMoveId > 0) {
                        $fundMovesWithDds[$fundMoveId] = $bamId;
                        $amortTypes[$typeId] = $typeId;
                        $oddsAmorted[$typeId][$n] += $summ;
                        $dtFunding = (int)($fundMoves[$fundMoveId]['dt_amort_start'] ?? 0);
                        if (!($dtFunding >= $period['start'] && $dtFunding < $period['end'])) {
                            $fundDebKred[$bamId] = [
                                'fund_id' => $fundMoveId,
                                'summ' => $summ,
                                'dt_dps' => $dt,
                                'dt_fund' => $dtFunding,
                            ];
                        }
                    } else {
                        $oddsNonAmorted[$typeId][$n] += $summ;
                    }
                } elseif ($accType < 2) {
                    if ($direction > 0) {
                        $loanTo[$n] += $summ;
                        if ($summ != 0.0) {
                            $anyLoan = true;
                        }
                    } else {
                        $loanFrom[$n] += $summ;
                        if ($summ != 0.0) {
                            $anyLoan = true;
                        }
                    }
                }

                $accountsSrc[$accountId][$n] += $summ;

                if ($dtPromised > 0) {
                    $payload = [
                        'dt_dps' => $dt,
                        'dt_promised' => $dtPromised,
                        'summ' => $summ,
                        'id_account' => $accountId,
                        'id_type' => $typeId,
                    ];
                    if ($summ <= 0) {
                        $promisedPays[$bamId] = $payload;
                    } else {
                        $promisedIncomes[$bamId] = $payload;
                    }
                }
            }
        }

        $fundKredit = [];
        $fundDebit = [];
        $promisedPayPeriods = [];
        $promisedIncomePeriods = [];
        $oddsOpiuCorr = [];

        foreach ($allPeriods as $n => $period) {
            $ssStart = $period['start'];
            $ssEnd = $period['end'];
            $fundKredit[$n] = 0.0;
            $fundDebit[$n] = 0.0;
            $promisedPayPeriods[$n] = 0.0;
            $promisedIncomePeriods[$n] = 0.0;

            foreach ($fundDebKred as $fd) {
                if ($fd['dt_dps'] < $fd['dt_fund']) {
                    if (
                        ($ssStart > $fd['dt_dps'] || ($fd['dt_dps'] >= $ssStart && $fd['dt_dps'] < $ssEnd))
                        && $ssEnd < $fd['dt_fund']
                    ) {
                        $fundDebit[$n] += $fd['summ'];
                    }
                } else {
                    if (
                        ($ssStart > $fd['dt_fund'] || ($fd['dt_fund'] >= $ssStart && $fd['dt_fund'] < $ssEnd))
                        && $ssEnd < $fd['dt_dps']
                    ) {
                        $fundKredit[$n] += $fd['summ'];
                    }
                }
            }

            foreach ($promisedPays as $pp) {
                $idAccount = $pp['id_account'];
                $samePeriod = ($pp['dt_dps'] >= $ssStart && $pp['dt_dps'] < $ssEnd)
                    && ($pp['dt_promised'] >= $ssStart && $pp['dt_promised'] < $ssEnd);
                if ($samePeriod) {
                    continue;
                }
                if (
                    ($ssStart > $pp['dt_dps'] || ($pp['dt_dps'] >= $ssStart && $pp['dt_dps'] < $ssEnd))
                    && $ssEnd < $pp['dt_promised']
                ) {
                    $promisedPayPeriods[$n] += $pp['summ'];
                    if ($pp['dt_dps'] >= $ssStart && $pp['dt_dps'] < $ssEnd) {
                        $accountsSrc[$idAccount][$n] -= $pp['summ'];
                    }
                } elseif ($pp['dt_promised'] >= $ssStart && $pp['dt_promised'] < $ssEnd) {
                    $accountsSrc[$idAccount][$n] += $pp['summ'];
                }
            }

            foreach ($promisedIncomes as $pp) {
                $idAccount = $pp['id_account'];
                $idType = $pp['id_type'];
                $samePeriod = ($pp['dt_dps'] >= $ssStart && $pp['dt_dps'] < $ssEnd)
                    && ($pp['dt_promised'] >= $ssStart && $pp['dt_promised'] < $ssEnd);
                if ($samePeriod) {
                    continue;
                }
                if (
                    ($ssStart > $pp['dt_dps'] || ($pp['dt_dps'] >= $ssStart && $pp['dt_dps'] < $ssEnd))
                    && $ssEnd < $pp['dt_promised']
                ) {
                    $promisedIncomePeriods[$n] += $pp['summ'];
                    if ($pp['dt_dps'] >= $ssStart && $pp['dt_dps'] < $ssEnd) {
                        $accountsSrc[$idAccount][$n] -= $pp['summ'];
                        $oddsAll[$idType][$n] -= $pp['summ'];
                        if (!isset($oddsOpiuCorr[$idType][$n])) {
                            $oddsOpiuCorr[$idType][$n] = 0.0;
                        }
                        $oddsOpiuCorr[$idType][$n] += $pp['summ'];
                    }
                } elseif ($pp['dt_promised'] >= $ssStart && $pp['dt_promised'] < $ssEnd) {
                    $accountsSrc[$idAccount][$n] += $pp['summ'];
                    $oddsAll[$idType][$n] += $pp['summ'];
                    if (!isset($oddsOpiuCorr[$idType][$n])) {
                        $oddsOpiuCorr[$idType][$n] = 0.0;
                    }
                    $oddsOpiuCorr[$idType][$n] -= $pp['summ'];
                }
            }
        }

        $fundValues = [];
        $fundDebitRefill = [];
        $fundCreditRefill = [];
        $fundDebitRefillByMoney = [];
        $fundCreditRefillByMoney = [];
        $fundByCap = [];
        $fundByFund = [];
        $fundByFundsCredit = [];
        $fundByFundsDebit = [];
        $fundByReval = [];
        $fundByDeval = [];
        $fundByDestroy = [];

        foreach (array_keys($allPeriods) as $n) {
            $fundDebitRefill[$n] = 0.0;
            $fundCreditRefill[$n] = 0.0;
            $fundDebitRefillByMoney[$n] = 0.0;
            $fundCreditRefillByMoney[$n] = 0.0;
            $fundByCap[$n] = 0.0;
            $fundByFund[$n] = 0.0;
            $fundByFundsCredit[$n] = 0.0;
            $fundByFundsDebit[$n] = 0.0;
            $fundByReval[$n] = 0.0;
            $fundByDeval[$n] = 0.0;
            $fundByDestroy[$n] = 0.0;
            $fundValues[$n] = [];
        }

        if ($hasFunds) {
            foreach ($fundMoves as $bfmId => $bfm) {
                $dt = (int)$bfm['dt_amort_start'];
                if ($year > 0 && (int)date('Y', $dt) > $year) {
                    break;
                }
                $summ = round((float)$bfm['summ'], 2);
                $sumOutFund = round((float)$bfm['summ_out_fund'], 2);
                $sumOutCap = round((float)$bfm['summ_out_cap'], 2);
                $sumOutDestroy = round((float)$bfm['summ_out_destroy'], 2);
                $fundId = (int)$bfm['id_fund'];
                $fundType = (int)($funds['leaf'][$fundId]['type'] ?? 2);
                $fundPid = (int)($funds['leaf'][$fundId]['pid'] ?? 0);
                $moveType = (int)$bfm['id_type'];
                $direction = (int)$bfm['direction'];

                foreach ($allPeriods as $n => $period) {
                    if ($dt < $period['start'] || $dt >= $period['end']) {
                        continue;
                    }
                    if ($fundType < 2) {
                        if (!isset($fundValues[$n][$fundPid])) {
                            $fundValues[$n][$fundPid] = 0.0;
                        }
                        $fundValues[$n][$fundPid] += $summ * $direction;
                    }
                    if ($fundType > 1) {
                        $fundByFundsDebit[$n] += $sumOutFund;
                    } else {
                        $fundByFundsCredit[$n] += $sumOutFund;
                    }
                    if ($moveType === 1) {
                        $fundByFund[$n] += $sumOutFund;
                        $fundByCap[$n] += $sumOutCap;
                    }
                    if ($moveType === 3) {
                        $fundByReval[$n] += $summ;
                    }
                    if ($moveType === 4) {
                        $fundByDeval[$n] += $summ;
                    }
                    if ($moveType === 5) {
                        $fundByDestroy[$n] += $sumOutDestroy;
                    }
                    if ($moveType === 2) {
                        if ($fundType > 1) {
                            if (isset($fundMovesWithDds[$bfmId])) {
                                $fundDebitRefillByMoney[$n] += $summ;
                            } else {
                                $fundDebitRefill[$n] += $summ;
                            }
                        } else {
                            if (isset($fundMovesWithDds[$bfmId])) {
                                $fundCreditRefillByMoney[$n] += $summ;
                            } else {
                                $fundCreditRefill[$n] += $summ;
                            }
                        }
                    }
                }
            }
        }

        $periodLabelsAll = [];
        $periodYearsAll = [];
        foreach ($allPeriods as $n => $p) {
            // Monthly labels when drilled into a year OR in YoY (always monthly buckets).
            $periodLabelsAll[$n] = ($year > 0 || $yoy)
                ? sprintf('%04d/%02d', $p['Y'], $p['m'])
                : (string)$p['Y'];
            $periodYearsAll[$n] = (int)$p['Y'];
        }

        $ctx = [
            'periods' => $allPeriods,
            'period_labels' => $periodLabelsAll,
            'types_ins' => $typesIns,
            'types_outs' => $typesOuts,
            'odds_all' => $oddsAll,
            'odds_amorted' => $oddsAmorted,
            'odds_non_amorted' => $oddsNonAmorted,
            'odds_opiu_corr' => $oddsOpiuCorr,
            'amort_types' => $amortTypes,
            'loan_from' => $loanFrom,
            'loan_to' => $loanTo,
            'any_loan' => $anyLoan,
            'accounts' => $accounts,
            'accounts_src' => $accountsSrc,
            'fund_values' => $fundValues,
            'fund_debit' => $fundDebit,
            'fund_kredit' => $fundKredit,
            'promised_pay' => $promisedPayPeriods,
            'promised_income' => $promisedIncomePeriods,
            'fund_debit_refill' => $fundDebitRefill,
            'fund_credit_refill' => $fundCreditRefill,
            'fund_debit_refill_money' => $fundDebitRefillByMoney,
            'fund_credit_refill_money' => $fundCreditRefillByMoney,
            'fund_by_cap' => $fundByCap,
            'fund_by_funds_credit' => $fundByFundsCredit,
            'fund_by_funds_debit' => $fundByFundsDebit,
            'fund_by_reval' => $fundByReval,
            'fund_by_deval' => $fundByDeval,
            'fund_by_destroy' => $fundByDestroy,
            'funds_pids' => $funds['pids'],
            'has_funds' => $hasFunds,
        ];

        $odds = self::buildOdds($ctx);
        $opiu = self::buildOpiu($ctx, $odds);
        $accountsMatrix = self::buildAccountsMatrix($ctx);
        $balance = self::buildBalance($ctx, $odds, $opiu, $accountsMatrix);
        $kanal = self::buildKanal($ctx, $opiu, $balance);
        $fundsMatrix = $hasFunds ? self::buildFundsMatrix($ctx, $balance) : null;

        $meta = self::buildMeta($odds, $opiu, $balance, $kanal, $fundsMatrix);
        $linkGroups = self::linkGroups();

        // Snapshot full matrices (incl. chart-only periods) before table slice/pairing.
        $oddsRaw = $odds;
        $opiuRaw = $opiu;
        $kanalRaw = $kanal;

        $odds = self::sliceMatrix($odds, $displayNs, $periodLabelsAll);
        $opiu = self::sliceMatrix($opiu, $displayNs, $periodLabelsAll);
        $accountsMatrix = self::sliceMatrix($accountsMatrix, $displayNs, $periodLabelsAll);
        $balance = self::sliceMatrix($balance, $displayNs, $periodLabelsAll);
        $kanal = self::sliceMatrix($kanal, $displayNs, $periodLabelsAll);
        if ($fundsMatrix !== null) {
            $fundsMatrix = self::sliceMatrix($fundsMatrix, $displayNs, $periodLabelsAll);
        }

        $periodLabels = [];
        $periodYears = [];
        $periodMonths = [];
        foreach ($displayNs as $n) {
            $periodLabels[$n] = $periodLabelsAll[$n];
            $periodYears[$n] = $periodYearsAll[$n];
            $periodMonths[$n] = (int)($allPeriods[$n]['m'] ?? 0);
        }

        $cellLinks = $yoy ? [] : self::buildCellLinks(
            $displayNs,
            $periodYears,
            $periodMonths,
            $year,
            $typesIns,
            $typesOuts,
            $hasFunds
        );

        $yoyLabelA = '';
        $yoyLabelB = '';
        $showSum = true;
        $outYear = $year;
        $yoyDiffCols = [];
        $yoyChartPayload = null;
        $yoyYearsPayload = null;

        if ($yoy && $yoyGrid !== null) {
            $showSum = false;
            $yoyLabelA = (string)$yoyGrid['label_a'];
            $yoyLabelB = (string)$yoyGrid['label_b'];
            $outYear = $yoyMode === 'month' ? (int)$yoyGrid['nav_year'] : 0;

            $yoyChartPayload = FinanceYoy::buildCompareChartPayload(
                $opiuRaw,
                $oddsRaw,
                $kanalRaw,
                $yoyChartPairs
            );
            if ($yoyChartView === 'years' && $yoyChartYearMonthIdx !== []) {
                $yoyYearsPayload = FinanceYoy::buildMultiYearChartPayload(
                    $opiuRaw,
                    $oddsRaw,
                    $kanalRaw,
                    $yoyChartYearMonthIdx
                );
            }

            if ($yoyMode === 'month') {
                $paired = FinanceYoy::buildPairedMatrix($odds, $yoyGrid['pairs']);
                $odds = $paired['matrix'];
                $periodLabels = $paired['period_labels'];
                $yoyDiffCols = $paired['diff_cols'];
                $paired = FinanceYoy::buildPairedMatrix($opiu, $yoyGrid['pairs']);
                $opiu = $paired['matrix'];
                $paired = FinanceYoy::buildPairedMatrix($balance, $yoyGrid['pairs']);
                $balance = $paired['matrix'];
                $paired = FinanceYoy::buildPairedMatrix($accountsMatrix, $yoyGrid['pairs']);
                $accountsMatrix = $paired['matrix'];
                $paired = FinanceYoy::buildPairedMatrix($kanal, $yoyGrid['pairs']);
                $kanal = $paired['matrix'];
                if ($fundsMatrix !== null) {
                    $paired = FinanceYoy::buildPairedMatrix($fundsMatrix, $yoyGrid['pairs']);
                    $fundsMatrix = $paired['matrix'];
                }
            } else {
                $idxA = $yoyGrid['idx_a'];
                $idxB = $yoyGrid['idx_b'];
                $kanalTemplate = $kanal;
                $paired = FinanceYoy::buildAggPairedMatrix($odds, $idxA, $idxB, $yoyLabelA, $yoyLabelB, 'ODDS');
                $odds = $paired['matrix'];
                $periodLabels = $paired['period_labels'];
                $yoyDiffCols = $paired['diff_cols'];
                $paired = FinanceYoy::buildAggPairedMatrix($opiu, $idxA, $idxB, $yoyLabelA, $yoyLabelB, 'OPIU');
                $opiu = $paired['matrix'];
                $paired = FinanceYoy::buildAggPairedMatrix($balance, $idxA, $idxB, $yoyLabelA, $yoyLabelB, 'BALANCE');
                $balance = $paired['matrix'];
                $paired = FinanceYoy::buildAggPairedMatrix($accountsMatrix, $idxA, $idxB, $yoyLabelA, $yoyLabelB, 'ACCOUNTS');
                $accountsMatrix = $paired['matrix'];
                if ($fundsMatrix !== null) {
                    $paired = FinanceYoy::buildAggPairedMatrix($fundsMatrix, $idxA, $idxB, $yoyLabelA, $yoyLabelB, 'FUND_MATRIX');
                    $fundsMatrix = $paired['matrix'];
                }
                $kanal = FinanceYoy::recalculateKanal(
                    $opiu,
                    $balance,
                    $kanalTemplate,
                    $typesOuts,
                    $yoyLabelA,
                    $yoyLabelB
                );
            }

            $periodYears = [];
            $periodMonths = [];
            foreach (array_keys($periodLabels) as $cn) {
                $periodYears[$cn] = 0;
                $periodMonths[$cn] = 0;
            }
            $meta = self::buildMeta($odds, $opiu, $balance, $kanal, $fundsMatrix);
        }

        return [
            'year' => $outYear,
            'years' => $years,
            'y_start' => $yStart,
            'y_end' => $yEnd,
            'period_labels' => $periodLabels,
            'period_years' => $periodYears,
            'period_months' => $periodMonths,
            'odds' => $odds,
            'opiu' => $opiu,
            'accounts' => $accountsMatrix,
            'balance' => $balance,
            'kanal' => $kanal,
            'funds' => $fundsMatrix,
            'meta' => $meta,
            'link_groups' => $linkGroups,
            'cell_links' => $cellLinks,
            'select' => $select,
            'show_sum' => $showSum,
            'yoy' => $yoy,
            'yoy_mode' => $yoy ? $yoyMode : '',
            'yoy_label_a' => $yoyLabelA,
            'yoy_label_b' => $yoyLabelB,
            'yoy_as_of' => $yoy ? date('Y-m-d', $yoyAsOf) : '',
            'yoy_nav_year' => $yoy ? $yoyNavYear : 0,
            'yoy_diff_cols' => $yoyDiffCols,
            'yoy_chart_view' => $yoy ? $yoyChartView : '',
            'yoy_years' => $yoy ? $yoyYears : 0,
            'yoy_chart_payload' => $yoyChartPayload,
            'yoy_years_payload' => $yoyYearsPayload,
            'empty' => false,
        ];
    }

    /**
     * @return array{
     *   year:int, years:list<int>, y_start:int, y_end:int,
     *   period_labels:array<int,string>, period_years:array<int,int>,
     *   odds:array, opiu:array, accounts:array, balance:array, kanal:array,
     *   funds:null, meta:array, link_groups:array, select:int, empty:bool
     * }
     */
    private static function emptyResult(int $year, int $select): array
    {
        return [
            'year' => $year,
            'years' => [],
            'y_start' => 0,
            'y_end' => 0,
            'period_labels' => [],
            'period_years' => [],
            'period_months' => [],
            'odds' => [],
            'opiu' => [],
            'accounts' => [],
            'balance' => [],
            'kanal' => [],
            'funds' => null,
            'meta' => [],
            'link_groups' => self::linkGroups(),
            'cell_links' => [],
            'select' => $select,
            'show_sum' => true,
            'yoy' => false,
            'yoy_mode' => '',
            'yoy_label_a' => '',
            'yoy_label_b' => '',
            'yoy_as_of' => '',
            'yoy_nav_year' => 0,
            'yoy_diff_cols' => [],
            'yoy_chart_view' => '',
            'yoy_years' => 0,
            'yoy_chart_payload' => null,
            'yoy_years_payload' => null,
            'empty' => true,
        ];
    }

    /** @return array<int,list<string>> */
    private static function linkGroups(): array
    {
        return [
            1 => ['ebitda', 'net_1'],
            2 => ['funds_pay', 'fund_total_pay'],
            3 => ['bal_cash', 'fund_cash_accounts', 'acc_sum'],
            4 => ['bal_da_total', 'fund_os_end'],
            5 => ['fund_amort_destroy', 'bal_liquidation'],
            6 => ['fund_amort_spend', 'fund_from_credit'],
            14 => ['opiu_e', 'bal_retained'],
            15 => ['bal_debit_accum', 'fund_debit_end'],
        ];
    }

    /**
     * @return array<int,array{start:int,end:int,Y:int,m:int}>
     */
    private static function buildPeriods(int $year, int $yStart, int $yEnd, int $mEnd): array
    {
        $out = [];
        $n = 1;
        $nowY = (int)date('Y');
        $nowM = (int)date('n');

        if ($year > 0) {
            // Monthly from data start through selected year (for carry-forward).
            for ($y = $yStart; $y <= $year; $y++) {
                $lastM = 12;
                if ($y === $nowY) {
                    $lastM = $nowM;
                }
                if ($y === $yEnd) {
                    $lastM = min($lastM, $mEnd);
                }
                if ($y === $year && $year === $nowY) {
                    $lastM = min($lastM, $nowM);
                }
                if ($y === $year && $year === $yEnd) {
                    $lastM = min($lastM, $mEnd);
                }
                for ($m = 1; $m <= $lastM; $m++) {
                    $start = mktime(0, 0, 0, $m, 1, $y) ?: 0;
                    $end = mktime(23, 59, 59, $m + 1, 0, $y) ?: 0;
                    $out[$n] = ['start' => $start, 'end' => $end, 'Y' => $y, 'm' => $m];
                    $n++;
                }
            }
        } else {
            for ($y = $yStart; $y <= $yEnd; $y++) {
                $lastM = ($y === $nowY) ? $nowM : 12;
                if ($y === $yEnd) {
                    $lastM = min($lastM, $mEnd);
                }
                $start = mktime(0, 0, 0, 1, 1, $y) ?: 0;
                $end = mktime(23, 59, 59, $lastM + 1, 0, $y) ?: 0;
                $out[$n] = ['start' => $start, 'end' => $end, 'Y' => $y, 'm' => 1];
                $n++;
            }
        }
        return $out;
    }

    /** @return array<int,array{id:int,name:string,id_type:int}> */
    private static function loadAccounts(): array
    {
        $out = [];
        foreach (Database::pdo()->query(
            'SELECT ID, NAME, ID_TYPE FROM base_accounts ORDER BY NAME ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['ID']] = [
                'id' => (int)$r['ID'],
                'name' => (string)$r['NAME'],
                'id_type' => (int)$r['ID_TYPE'],
            ];
        }
        return $out;
    }

    /**
     * @return array<int,array{id:int,name:string,odds_type:int,exclude_pl:int,is_div:int}>
     */
    private static function loadMoveTypes(int $direction): array
    {
        if (!Database::tableExists('base_account_move_types')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, NAME, ID_TYPE, EXCLUDE_PL, IS_DIV
             FROM base_account_move_types
             WHERE PID > 0 AND DIRECTION = ?
             ORDER BY ID_TYPE ASC, NUM ASC, NAME ASC'
        );
        $st->execute([$direction]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['ID']] = [
                'id' => (int)$r['ID'],
                'name' => (string)$r['NAME'],
                'odds_type' => (int)$r['ID_TYPE'],
                'exclude_pl' => (int)$r['EXCLUDE_PL'],
                'is_div' => (int)$r['IS_DIV'],
            ];
        }
        return $out;
    }

    /**
     * @return array{leaf:array<int,array{name:string,type:int,pid:int}>,pids:array<int,array{name:string,type:int}>}
     */
    private static function loadFunds(): array
    {
        $leaf = [];
        $pids = [];
        foreach (Database::pdo()->query(
            'SELECT ID, NAME, TYPE, PID FROM base_funds ORDER BY NAME ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $id = (int)$r['ID'];
            $pid = (int)$r['PID'];
            $row = [
                'name' => (string)$r['NAME'],
                'type' => (int)$r['TYPE'],
                'pid' => $pid,
            ];
            if ($pid > 0) {
                $leaf[$id] = $row;
            } else {
                $pids[$id] = ['name' => $row['name'], 'type' => $row['type']];
            }
        }
        return ['leaf' => $leaf, 'pids' => $pids];
    }

    /**
     * @return array<int,array{id_fund:int,id_type:int,direction:int,dt_amort_start:int,summ:float,summ_out_fund:float,summ_out_cap:float,summ_out_destroy:float}>
     */
    private static function loadFundMovements(): array
    {
        $out = [];
        $st = Database::pdo()->query(
            'SELECT ID, ID_FUND, ID_TYPE, DIRECTION, DT_AMORT_START, SUMM,
                    SUMM_OUT_FUND, SUMM_OUT_CAP, SUMM_OUT_DESTROY
             FROM base_fund_movements
             ORDER BY DT_AMORT_START ASC'
        );
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(int)$r['ID']] = [
                'id_fund' => (int)$r['ID_FUND'],
                'id_type' => (int)$r['ID_TYPE'],
                'direction' => (int)$r['DIRECTION'],
                'dt_amort_start' => (int)$r['DT_AMORT_START'],
                'summ' => (float)$r['SUMM'],
                'summ_out_fund' => (float)$r['SUMM_OUT_FUND'],
                'summ_out_cap' => (float)$r['SUMM_OUT_CAP'],
                'summ_out_destroy' => (float)$r['SUMM_OUT_DESTROY'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $ctx
     * @return array<string,array<int,float|string>>
     */
    private static function buildOdds(array $ctx): array
    {
        $periods = $ctx['periods'];
        $periodLabels = $ctx['period_labels'];
        $typesIns = $ctx['types_ins'];
        $typesOuts = $ctx['types_outs'];
        $oddsAll = $ctx['odds_all'];
        $oddsAmorted = $ctx['odds_amorted'];
        $oddsNonAmorted = $ctx['odds_non_amorted'];
        $amortTypes = $ctx['amort_types'];
        $loanFrom = $ctx['loan_from'];
        $loanTo = $ctx['loan_to'];
        $anyLoan = (bool)$ctx['any_loan'];

        $odds = [];
        $odds['period'][0] = t('finance.odds.period');
        $moveTypeNames = [
            1 => t('finance.odds.type.1'),
            2 => t('finance.odds.type.2'),
            3 => t('finance.odds.type.3'),
        ];

        foreach ($moveTypeNames as $mtId => $mtName) {
            $odds['in_h_' . $mtId][0] = t('finance.odds.in_header', ['name' => $mtName]);
            foreach ($typesIns as $tid => $td) {
                if ((int)$td['odds_type'] !== $mtId) {
                    continue;
                }
                $label = $td['name'];
                if (!empty($td['exclude_pl'])) {
                    $label .= ' ' . t('finance.odds.excl_pl');
                }
                $odds['t_' . $tid][0] = $label;
            }
            if ($anyLoan && $mtId === 3) {
                $odds['loan_in'][0] = t('finance.odds.loan_in') . ' ' . t('finance.odds.excl_pl');
            }
            $odds['in_tot_' . $mtId][0] = t('finance.odds.in_total', ['name' => $mtName]);
            $odds['out_h_' . $mtId][0] = t('finance.odds.out_header', ['name' => $mtName]);
            foreach ($typesOuts as $tid => $td) {
                if ((int)$td['odds_type'] !== $mtId) {
                    continue;
                }
                $label = $td['name'];
                if (!empty($td['exclude_pl'])) {
                    $label .= ' ' . t('finance.odds.excl_pl');
                }
                $odds['t_' . $tid][0] = $label;
            }
            if ($anyLoan && $mtId === 3) {
                $odds['loan_out'][0] = t('finance.odds.loan_out') . ' ' . t('finance.odds.excl_pl');
            }
            $odds['out_tot_' . $mtId][0] = t('finance.odds.out_total', ['name' => $mtName]);
            $odds['net_' . $mtId][0] = t('finance.odds.net', ['name' => $mtName]);
        }
        $odds['cf_total'][0] = t('finance.odds.total_cf');
        $odds['cash_start'][0] = t('finance.odds.cash_start');
        $odds['cash_end'][0] = t('finance.odds.cash_end');

        foreach ($periods as $n => $_p) {
            $odds['period'][$n] = $periodLabels[$n];
            $clearCf = 0.0;
            foreach ($moveTypeNames as $mtId => $mtName) {
                $odds['in_h_' . $mtId][$n] = $mtName;
                $moneyIn = 0.0;
                foreach ($typesIns as $tid => $td) {
                    if ((int)$td['odds_type'] !== $mtId) {
                        continue;
                    }
                    $v = (float)($oddsAll[$tid][$n] ?? 0.0);
                    $odds['t_' . $tid][$n] = $v;
                    $moneyIn += $v;
                }
                if ($anyLoan && $mtId === 3) {
                    $li = abs((float)($loanFrom[$n] ?? 0.0));
                    $odds['loan_in'][$n] = $li;
                    $moneyIn += $li;
                }
                $odds['in_tot_' . $mtId][$n] = $moneyIn;
                $odds['out_h_' . $mtId][$n] = '';
                $moneyOut = 0.0;
                foreach ($typesOuts as $tid => $td) {
                    if ((int)$td['odds_type'] !== $mtId) {
                        continue;
                    }
                    if (isset($amortTypes[$tid])) {
                        // Oper: moneyOutTotal += NON + AMORT; cell shows AMORT (or 0).
                        // Use NON+AMORT for both cell and total so CF and OPIU stay consistent.
                        $non = (float)($oddsNonAmorted[$tid][$n] ?? 0.0);
                        $am = (float)($oddsAmorted[$tid][$n] ?? 0.0);
                        $v = $non + $am;
                        $odds['t_' . $tid][$n] = $v;
                        $moneyOut += $v;
                    } else {
                        $v = (float)($oddsNonAmorted[$tid][$n] ?? 0.0);
                        $odds['t_' . $tid][$n] = $v;
                        $moneyOut += $v;
                    }
                }
                if ($anyLoan && $mtId === 3) {
                    $lo = abs((float)($loanTo[$n] ?? 0.0));
                    $odds['loan_out'][$n] = $lo;
                    $moneyOut -= $lo;
                }
                $odds['out_tot_' . $mtId][$n] = $moneyOut;
                $net = $moneyIn + $moneyOut;
                $odds['net_' . $mtId][$n] = $net;
                $clearCf += $net;
            }
            $odds['cf_total'][$n] = $clearCf;
            $prevEnd = $odds['cash_end'][$n - 1] ?? null;
            $odds['cash_start'][$n] = is_numeric($prevEnd) ? (float)$prevEnd : 0.0;
            $odds['cash_end'][$n] = (float)$odds['cash_start'][$n] + $clearCf;
        }
        return $odds;
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,array<int,float|string>> $odds
     * @return array<string,array<int,float|string>>
     */
    private static function buildOpiu(array $ctx, array $odds): array
    {
        $periods = $ctx['periods'];
        $periodLabels = $ctx['period_labels'];
        $typesIns = $ctx['types_ins'];
        $typesOuts = $ctx['types_outs'];
        $oddsOpiuCorr = $ctx['odds_opiu_corr'];
        $fcr = $ctx['fund_credit_refill'];
        $fdr = $ctx['fund_debit_refill'];
        $fcrBm = $ctx['fund_credit_refill_money'];
        $fdrBm = $ctx['fund_debit_refill_money'];
        $destroy = $ctx['fund_by_destroy'];

        // OPIU source = ODDS cells + promised OP IU corrections (oper ODDS_OPIU_2).
        $oddsOpiu = $odds;
        foreach ($oddsOpiuCorr as $tid => $byN) {
            $key = 't_' . $tid;
            foreach ($byN as $n => $corr) {
                if (!isset($oddsOpiu[$key][$n]) || !is_numeric($oddsOpiu[$key][$n])) {
                    $oddsOpiu[$key][$n] = 0.0;
                }
                $oddsOpiu[$key][$n] = (float)$oddsOpiu[$key][$n] + (float)$corr;
            }
        }

        $opiu = [];
        $opiu['period'][0] = t('finance.odds.period');
        $opiu['revenue'][0] = t('finance.opiu.revenue');
        $opiu['costs'][0] = t('finance.opiu.costs');
        $opiu['ebitdas'][0] = t('finance.opiu.ebitdas');
        $opiu['ebitda'][0] = t('finance.opiu.ebitda');
        $opiu['funds_pay'][0] = t('finance.opiu.funds');
        $opiu['ebit'][0] = t('finance.opiu.ebit');
        $opiu['invest_h'][0] = t('finance.opiu.invest_header');
        $opiu['invest_destroy'][0] = t('finance.opiu.invest_destroy');
        $opiu['invest_sale'][0] = t('finance.opiu.invest_sale');
        $opiu['invest_accel'][0] = t('finance.opiu.invest_accel');
        $opiu['invest_tot'][0] = t('finance.opiu.invest_total');
        $opiu['fin_h'][0] = t('finance.opiu.fin_header');
        foreach ($typesIns as $tid => $td) {
            if ($tid === 25) {
                continue;
            }
            if ((int)$td['odds_type'] === 3) {
                $opiu['t_' . $tid][0] = $td['name'];
            }
        }
        foreach ($typesOuts as $tid => $td) {
            if ((int)$td['odds_type'] === 3 && empty($td['is_div'])) {
                $opiu['t_' . $tid][0] = $td['name'];
            }
        }
        $opiu['ebi'][0] = t('finance.opiu.ebi');
        foreach ($typesOuts as $tid => $td) {
            if ((int)$td['odds_type'] === 3 && !empty($td['is_div'])) {
                $opiu['t_' . $tid][0] = $td['name'];
            }
        }
        $opiu['opiu_e'][0] = t('finance.opiu.e');

        foreach ($periods as $n => $_p) {
            $opiu['period'][$n] = $periodLabels[$n];
            $moneyIn = 0.0;
            foreach ($typesIns as $tid => $td) {
                if ($tid === 33) {
                    continue; // fund sale → invest_sale only
                }
                if (!empty($td['exclude_pl'])) {
                    continue;
                }
                $moneyIn += (float)($oddsOpiu['t_' . $tid][$n] ?? 0.0);
            }
            $moneyOut = 0.0;
            foreach ($typesOuts as $tid => $td) {
                if (!empty($td['exclude_pl'])) {
                    continue;
                }
                $moneyOut += (float)($oddsOpiu['t_' . $tid][$n] ?? 0.0);
            }
            $opiu['revenue'][$n] = $moneyIn;
            $opiu['costs'][$n] = $moneyOut;
            $opiu['ebitdas'][$n] = $moneyIn + $moneyOut;
            $opiu['ebitda'][$n] = $opiu['ebitdas'][$n];
            $opiu['funds_pay'][$n] = -((float)($fcr[$n] ?? 0.0) + (float)($fdr[$n] ?? 0.0));
            $opiu['ebit'][$n] = (float)$opiu['ebitda'][$n] + (float)$opiu['funds_pay'][$n];
            $opiu['invest_h'][$n] = '';
            $opiu['invest_destroy'][$n] = (float)($destroy[$n] ?? 0.0);
            $opiu['invest_sale'][$n] = (float)($oddsOpiu['t_33'][$n] ?? 0.0);
            $opiu['invest_accel'][$n] = -((float)($fcrBm[$n] ?? 0.0) + (float)($fdrBm[$n] ?? 0.0));
            $opiu['invest_tot'][$n] = (float)$opiu['ebit'][$n]
                + (float)$opiu['invest_destroy'][$n]
                + (float)$opiu['invest_sale'][$n]
                + (float)$opiu['invest_accel'][$n];
            $opiu['fin_h'][$n] = '';
            $fin = 0.0;
            foreach ($typesIns as $tid => $td) {
                if ($tid === 25) {
                    continue;
                }
                if ((int)$td['odds_type'] === 3) {
                    $v = (float)($oddsOpiu['t_' . $tid][$n] ?? 0.0);
                    $opiu['t_' . $tid][$n] = $v;
                    $fin += $v;
                }
            }
            foreach ($typesOuts as $tid => $td) {
                if ((int)$td['odds_type'] === 3 && empty($td['is_div'])) {
                    $v = (float)($oddsOpiu['t_' . $tid][$n] ?? 0.0);
                    $opiu['t_' . $tid][$n] = $v;
                    $fin += $v;
                }
            }
            $opiu['ebi'][$n] = (float)$opiu['invest_tot'][$n] + $fin;
            $divs = 0.0;
            foreach ($typesOuts as $tid => $td) {
                if ((int)$td['odds_type'] === 3 && !empty($td['is_div'])) {
                    $v = (float)($oddsOpiu['t_' . $tid][$n] ?? 0.0);
                    $opiu['t_' . $tid][$n] = $v;
                    $divs += $v;
                }
            }
            $opiu['opiu_e'][$n] = (float)$opiu['ebi'][$n] + $divs;
        }
        return $opiu;
    }

    /**
     * @param array<string,mixed> $ctx
     * @return array<string,array<int,float|string>>
     */
    private static function buildAccountsMatrix(array $ctx): array
    {
        $periods = $ctx['periods'];
        $periodLabels = $ctx['period_labels'];
        $accounts = $ctx['accounts'];
        $accountsSrc = $ctx['accounts_src'];

        $m = [];
        $m['period'][0] = t('finance.odds.period');
        foreach ($accounts as $acc) {
            $key = 'a_' . $acc['id'];
            $label = $acc['name'];
            if ((int)$acc['id_type'] < 2) {
                $label .= ' (' . t('finance.accounts.credit_mark') . ')';
            }
            $m[$key][0] = $label;
        }
        $m['acc_sum'][0] = t('finance.accounts.sum');
        $m['acc_credit_sum'][0] = t('finance.accounts.credit_sum');

        foreach ($periods as $n => $_p) {
            $m['period'][$n] = $periodLabels[$n];
            $sumDebit = 0.0;
            $sumCredit = 0.0;
            foreach ($accounts as $acc) {
                $bal = 0.0;
                if (isset($accountsSrc[$acc['id']])) {
                    foreach ($accountsSrc[$acc['id']] as $pn => $sv) {
                        if ($pn <= $n) {
                            $bal += $sv;
                        }
                    }
                }
                $m['a_' . $acc['id']][$n] = $bal;
                if ((int)$acc['id_type'] > 1) {
                    $sumDebit += $bal;
                } else {
                    $sumCredit += $bal;
                }
            }
            $m['acc_sum'][$n] = $sumDebit;
            $m['acc_credit_sum'][$n] = $sumCredit;
        }
        return $m;
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,array<int,float|string>> $odds
     * @param array<string,array<int,float|string>> $opiu
     * @param array<string,array<int,float|string>> $accounts
     * @return array<string,array<int,float|string>>
     */
    private static function buildBalance(array $ctx, array $odds, array $opiu, array $accounts): array
    {
        $periods = $ctx['periods'];
        $periodLabels = $ctx['period_labels'];
        $fundValues = $ctx['fund_values'];
        $fundDebit = $ctx['fund_debit'];
        $fundKredit = $ctx['fund_kredit'];
        $promisedPay = $ctx['promised_pay'];
        $promisedIncome = $ctx['promised_income'];
        $fundByReval = $ctx['fund_by_reval'];
        $fundByDeval = $ctx['fund_by_deval'];
        $fundByDestroy = $ctx['fund_by_destroy'];
        $fundDebitRefill = $ctx['fund_debit_refill'];
        $fundByFundsDebit = $ctx['fund_by_funds_debit'];
        $fundsPids = $ctx['funds_pids'];

        $b = [];
        $b['period'][0] = t('finance.odds.period');
        $b['assets_h'][0] = t('finance.balance.assets');
        $b['da_h'][0] = t('finance.balance.da');
        foreach ($fundsPids as $pid => $pd) {
            if ((int)$pd['type'] < 2) {
                $b['da_fund_' . $pid][0] = $pd['name'];
            }
        }
        $b['bal_da_total'][0] = t('finance.balance.da_total');
        $b['ca_h'][0] = t('finance.balance.ca');
        $b['bal_debtors'][0] = t('finance.balance.debtors');
        $b['bal_debtors_other'][0] = t('finance.balance.debtors_other');
        $b['bal_cash'][0] = t('finance.balance.cash');
        $b['ca_tot'][0] = t('finance.balance.ca_total');
        $b['assets_total'][0] = t('finance.balance.assets_total');
        $b['liab_h'][0] = t('finance.balance.liabilities');
        $b['eq_h'][0] = t('finance.balance.equity');
        $b['bal_equity_cap'][0] = t('finance.balance.equity_cap');
        $b['bal_retained_prev'][0] = t('finance.balance.retained_prev');
        $b['bal_retained'][0] = t('finance.balance.retained');
        $b['bal_reval'][0] = t('finance.balance.reval');
        $b['bal_liquidation'][0] = t('finance.balance.liquidation');
        $b['eq_tot'][0] = t('finance.balance.equity_total');
        $b['lt_h'][0] = t('finance.balance.long_debt');
        $b['bal_bank'][0] = t('finance.balance.bank');
        $b['bal_promised'][0] = t('finance.balance.promised');
        $b['lt_tot'][0] = t('finance.balance.long_total');
        $b['st_h'][0] = t('finance.balance.short_debt');
        $b['bal_creditors'][0] = t('finance.balance.creditors');
        $b['bal_debit_accum'][0] = t('finance.balance.debit_accum');
        $b['st_tot'][0] = t('finance.balance.short_total');
        $b['liab_total'][0] = t('finance.balance.liab_total');
        $b['delta'][0] = t('finance.balance.delta');

        foreach ($periods as $n => $_p) {
            $b['period'][$n] = $periodLabels[$n];
            $b['assets_h'][$n] = '';
            $b['da_h'][$n] = '';
            $daTotal = 0.0;
            foreach ($fundsPids as $pid => $pd) {
                if ((int)$pd['type'] >= 2) {
                    continue;
                }
                $key = 'da_fund_' . $pid;
                $cur = (float)($fundValues[$n][$pid] ?? 0.0);
                if ($n > 1 && isset($b[$key][$n - 1]) && is_numeric($b[$key][$n - 1])) {
                    $b[$key][$n] = abs((float)$b[$key][$n - 1]) - $cur;
                } else {
                    $b[$key][$n] = abs($cur);
                }
                $daTotal += (float)$b[$key][$n];
            }
            $b['bal_da_total'][$n] = abs($daTotal);
            $b['ca_h'][$n] = '';
            $b['bal_debtors'][$n] = abs((float)($fundDebit[$n] ?? 0.0));
            $b['bal_debtors_other'][$n] = abs((float)($promisedIncome[$n] ?? 0.0));
            $b['bal_cash'][$n] = (float)($accounts['acc_sum'][$n] ?? 0.0);
            $b['ca_tot'][$n] = (float)$b['bal_debtors'][$n]
                + (float)$b['bal_debtors_other'][$n]
                + (float)$b['bal_cash'][$n];
            $b['assets_total'][$n] = (float)$b['bal_da_total'][$n] + (float)$b['ca_tot'][$n];

            $b['liab_h'][$n] = '';
            $b['eq_h'][$n] = '';
            $akMove = (float)($odds['t_25'][$n] ?? 0.0);
            if ($n > 1 && isset($b['bal_equity_cap'][$n - 1]) && is_numeric($b['bal_equity_cap'][$n - 1])) {
                $b['bal_equity_cap'][$n] = (float)$b['bal_equity_cap'][$n - 1] + $akMove;
            } else {
                $b['bal_equity_cap'][$n] = $akMove;
            }

            if ($n > 1 && isset($opiu['opiu_e'][$n - 1]) && is_numeric($opiu['opiu_e'][$n - 1])) {
                $prevNppl = ($n > 1 && isset($b['bal_retained_prev'][$n - 1]) && is_numeric($b['bal_retained_prev'][$n - 1]))
                    ? (float)$b['bal_retained_prev'][$n - 1]
                    : 0.0;
                $b['bal_retained_prev'][$n] = $prevNppl + (float)$opiu['opiu_e'][$n - 1];
            } else {
                $b['bal_retained_prev'][$n] = 0.0;
            }
            $b['bal_retained'][$n] = (float)($opiu['opiu_e'][$n] ?? 0.0);

            $reval = (float)($fundByReval[$n] ?? 0.0) - (float)($fundByDeval[$n] ?? 0.0);
            if ($n > 1 && isset($b['bal_reval'][$n - 1]) && is_numeric($b['bal_reval'][$n - 1])) {
                $reval += (float)$b['bal_reval'][$n - 1];
            }
            $b['bal_reval'][$n] = $reval;

            $liq = (float)($fundByDestroy[$n] ?? 0.0);
            if ($n > 1 && isset($b['bal_liquidation'][$n - 1]) && is_numeric($b['bal_liquidation'][$n - 1])) {
                $liq += (float)$b['bal_liquidation'][$n - 1];
            }
            $b['bal_liquidation'][$n] = $liq;

            $b['eq_tot'][$n] = (float)$b['bal_equity_cap'][$n]
                + (float)$b['bal_retained_prev'][$n]
                + (float)$b['bal_retained'][$n]
                + (float)$b['bal_reval'][$n]
                - (float)$b['bal_liquidation'][$n];

            $b['lt_h'][$n] = '';
            $b['bal_bank'][$n] = abs((float)($accounts['acc_credit_sum'][$n] ?? 0.0));
            $b['bal_promised'][$n] = abs((float)($promisedPay[$n] ?? 0.0));
            $b['lt_tot'][$n] = (float)$b['bal_bank'][$n] + (float)$b['bal_promised'][$n];

            $b['st_h'][$n] = '';
            $b['bal_creditors'][$n] = abs((float)($fundKredit[$n] ?? 0.0));
            $debAccum = (float)($fundDebitRefill[$n] ?? 0.0) - (float)($fundByFundsDebit[$n] ?? 0.0);
            if ($n > 1 && isset($b['bal_debit_accum'][$n - 1]) && is_numeric($b['bal_debit_accum'][$n - 1])) {
                $debAccum += (float)$b['bal_debit_accum'][$n - 1];
            }
            $b['bal_debit_accum'][$n] = $debAccum;
            $b['st_tot'][$n] = (float)$b['bal_creditors'][$n] + (float)$b['bal_debit_accum'][$n];
            $b['liab_total'][$n] = (float)$b['eq_tot'][$n] + (float)$b['lt_tot'][$n] + (float)$b['st_tot'][$n];
            $b['delta'][$n] = round((float)$b['assets_total'][$n] - (float)$b['liab_total'][$n], 2);
        }
        return $b;
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,array<int,float|string>> $opiu
     * @param array<string,array<int,float|string>> $balance
     * @return array<string,array<int,float|string>>
     */
    private static function buildKanal(array $ctx, array $opiu, array $balance): array
    {
        $periods = $ctx['periods'];
        $periodLabels = $ctx['period_labels'];
        $typesOuts = $ctx['types_outs'];

        $k = [];
        $k['period'][0] = t('finance.odds.period');
        $k['ros'][0] = t('finance.kanal.ros');
        $k['roce'][0] = t('finance.kanal.roce');
        $k['roe'][0] = t('finance.kanal.roe');
        $k['roe_ebit'][0] = t('finance.kanal.roe_ebit');
        $k['roce_ebit'][0] = t('finance.kanal.roce_ebit');
        $k['dpr'][0] = t('finance.kanal.dpr');

        foreach ($periods as $n => $_p) {
            $k['period'][$n] = $periodLabels[$n];
            $rev = (float)($opiu['revenue'][$n] ?? 0.0);
            $ebi = (float)($opiu['ebi'][$n] ?? 0.0);
            $ebit = (float)($opiu['ebit'][$n] ?? 0.0);
            $eq = (float)($balance['eq_tot'][$n] ?? 0.0);
            $lt = (float)($balance['lt_tot'][$n] ?? 0.0);
            $k['ros'][$n] = $rev != 0.0 ? round($ebi * 100 / $rev, 2) : 0.0;
            $cap = $eq + $lt;
            $k['roce'][$n] = $cap > 0.0 ? round($ebi * 100 / $cap, 2) : 0.0;
            if ($eq != 0.0) {
                $k['roe'][$n] = round($ebi * 100 / $eq, 2);
                $k['roe_ebit'][$n] = round($ebit * 100 / $eq, 2);
                $k['roce_ebit'][$n] = $cap != 0.0 ? round($ebit * 100 / $cap, 2) : 0.0;
            } else {
                $k['roe'][$n] = 0.0;
                $k['roe_ebit'][$n] = 0.0;
                $k['roce_ebit'][$n] = 0.0;
            }
            $divs = 0.0;
            foreach ($typesOuts as $tid => $td) {
                if ((int)$td['odds_type'] === 3 && !empty($td['is_div'])) {
                    $divs += abs((float)($opiu['t_' . $tid][$n] ?? 0.0));
                }
            }
            $k['dpr'][$n] = $ebi != 0.0 ? round($divs * 100 / $ebi, 2) : 0.0;
        }
        return $k;
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,array<int,float|string>> $balance
     * @return array<string,array<int,float|string>>
     */
    private static function buildFundsMatrix(array $ctx, array $balance): array
    {
        $periods = $ctx['periods'];
        $periodLabels = $ctx['period_labels'];
        $byCap = $ctx['fund_by_cap'];
        $byFundsCredit = $ctx['fund_by_funds_credit'];
        $byFundsDebit = $ctx['fund_by_funds_debit'];
        $byReval = $ctx['fund_by_reval'];
        $byDeval = $ctx['fund_by_deval'];
        $byDestroy = $ctx['fund_by_destroy'];
        $creditRefill = $ctx['fund_credit_refill'];
        $creditRefillMoney = $ctx['fund_credit_refill_money'];
        $debitRefill = $ctx['fund_debit_refill'];

        $m = [];
        $m['period'][0] = t('finance.odds.period');
        $m['fund_credit_h'][0] = t('finance.funds.credit');
        $m['fund_os_h'][0] = t('finance.funds.os_header');
        $m['fund_os_start'][0] = t('finance.funds.os_start');
        $m['fund_from_cap'][0] = t('finance.funds.from_cap');
        $m['fund_from_credit'][0] = t('finance.funds.from_credit');
        $m['fund_reval'][0] = t('finance.funds.reval');
        $m['fund_deval'][0] = t('finance.funds.deval');
        $m['fund_amort'][0] = t('finance.funds.amort');
        $m['fund_amort_accel'][0] = t('finance.funds.amort_accel');
        $m['fund_os_end'][0] = t('finance.funds.os_end');
        $m['fund_amort_h'][0] = t('finance.funds.amort_header');
        $m['fund_amort_start'][0] = t('finance.funds.amort_start');
        $m['fund_amort_accrued'][0] = t('finance.funds.amort_accrued');
        $m['fund_amort_accrued_accel'][0] = t('finance.funds.amort_accrued_accel');
        $m['fund_amort_spend'][0] = t('finance.funds.amort_spend');
        $m['fund_amort_destroy'][0] = t('finance.funds.amort_destroy');
        $m['fund_amort_end'][0] = t('finance.funds.amort_end');
        $m['fund_debit_h'][0] = t('finance.funds.debit');
        $m['fund_debit_start'][0] = t('finance.funds.debit_start');
        $m['fund_debit_accrued'][0] = t('finance.funds.debit_accrued');
        $m['fund_debit_spend'][0] = t('finance.funds.debit_spend');
        $m['fund_debit_end'][0] = t('finance.funds.debit_end');
        $m['fund_total_h'][0] = t('finance.funds.total_header');
        $m['fund_total_pay'][0] = t('finance.funds.total_pay');
        $m['fund_capacity'][0] = t('finance.funds.capacity');
        $m['fund_cash_in_funds'][0] = t('finance.funds.cash_in_funds');
        $m['fund_cash_accounts'][0] = t('finance.funds.cash_accounts');
        $m['fund_coverage'][0] = t('finance.funds.coverage');

        foreach ($periods as $n => $_p) {
            $osStart = ($n > 1 && isset($m['fund_os_end'][$n - 1]) && is_numeric($m['fund_os_end'][$n - 1]))
                ? (float)$m['fund_os_end'][$n - 1] : 0.0;
            $amortStart = ($n > 1 && isset($m['fund_amort_end'][$n - 1]) && is_numeric($m['fund_amort_end'][$n - 1]))
                ? (float)$m['fund_amort_end'][$n - 1] : 0.0;
            $debitStart = ($n > 1 && isset($m['fund_debit_end'][$n - 1]) && is_numeric($m['fund_debit_end'][$n - 1]))
                ? (float)$m['fund_debit_end'][$n - 1] : 0.0;

            $m['period'][$n] = $periodLabels[$n];
            $m['fund_credit_h'][$n] = '';
            $m['fund_os_h'][$n] = '';
            $m['fund_os_start'][$n] = $osStart;
            $m['fund_from_cap'][$n] = (float)($byCap[$n] ?? 0.0);
            $m['fund_from_credit'][$n] = (float)($byFundsCredit[$n] ?? 0.0);
            $m['fund_reval'][$n] = (float)($byReval[$n] ?? 0.0);
            $m['fund_deval'][$n] = -((float)($byDeval[$n] ?? 0.0));
            $m['fund_amort'][$n] = -((float)($creditRefill[$n] ?? 0.0));
            $m['fund_amort_accel'][$n] = -((float)($creditRefillMoney[$n] ?? 0.0));
            $m['fund_os_end'][$n] = (float)$m['fund_os_start'][$n]
                + (float)$m['fund_from_cap'][$n]
                + (float)$m['fund_from_credit'][$n]
                + (float)$m['fund_reval'][$n]
                + (float)$m['fund_deval'][$n]
                + (float)$m['fund_amort'][$n]
                + (float)$m['fund_amort_accel'][$n];

            $m['fund_amort_h'][$n] = '';
            $m['fund_amort_start'][$n] = $amortStart;
            $m['fund_amort_accrued'][$n] = (float)($creditRefill[$n] ?? 0.0);
            $m['fund_amort_accrued_accel'][$n] = (float)($creditRefillMoney[$n] ?? 0.0);
            $m['fund_amort_spend'][$n] = -((float)($byFundsCredit[$n] ?? 0.0));
            $m['fund_amort_destroy'][$n] = -((float)($byDestroy[$n] ?? 0.0));
            $m['fund_amort_end'][$n] = (float)$m['fund_amort_start'][$n]
                + (float)$m['fund_amort_accrued'][$n]
                + (float)$m['fund_amort_accrued_accel'][$n]
                + (float)$m['fund_amort_spend'][$n]
                + (float)$m['fund_amort_destroy'][$n];

            $m['fund_debit_h'][$n] = '';
            $m['fund_debit_start'][$n] = $debitStart;
            $m['fund_debit_accrued'][$n] = (float)($debitRefill[$n] ?? 0.0);
            $m['fund_debit_spend'][$n] = -((float)($byFundsDebit[$n] ?? 0.0));
            $m['fund_debit_end'][$n] = (float)$m['fund_debit_start'][$n]
                + (float)$m['fund_debit_accrued'][$n]
                + (float)$m['fund_debit_spend'][$n];

            $m['fund_total_h'][$n] = '';
            $m['fund_total_pay'][$n] = (float)$m['fund_amort_accrued'][$n]
                + (float)$m['fund_amort_accrued_accel'][$n]
                + (float)$m['fund_debit_accrued'][$n];
            $m['fund_capacity'][$n] = (float)$m['fund_os_end'][$n]
                + (float)$m['fund_amort_end'][$n]
                + (float)$m['fund_debit_end'][$n];
            $m['fund_cash_in_funds'][$n] = (float)$m['fund_amort_end'][$n] + (float)$m['fund_debit_end'][$n];
            $m['fund_cash_accounts'][$n] = (float)($balance['bal_cash'][$n] ?? 0.0);
            $cash = (float)$m['fund_cash_accounts'][$n];
            $inFunds = (float)$m['fund_cash_in_funds'][$n];
            if ($cash > 0.0) {
                $m['fund_coverage'][$n] = $inFunds != 0.0 ? round($cash * 100 / $inFunds, 2) : 100.0;
            } else {
                $m['fund_coverage'][$n] = 0.0;
            }
        }
        return $m;
    }

    /**
     * @param array<string,array<int,float|string>> $odds
     * @param array<string,array<int,float|string>> $opiu
     * @param array<string,array<int,float|string>> $balance
     * @param array<string,array<int,float|string>> $kanal
     * @param array<string,array<int,float|string>>|null $funds
     * @return array<string,array<string,array{hl?:bool,group?:int}>>
     */
    private static function buildMeta(
        array $odds,
        array $opiu,
        array $balance,
        array $kanal,
        ?array $funds
    ): array {
        $meta = ['odds' => [], 'opiu' => [], 'accounts' => [], 'balance' => [], 'kanal' => [], 'funds' => []];
        $groups = self::linkGroups();
        $keyToGroup = [];
        foreach ($groups as $g => $keys) {
            foreach ($keys as $k) {
                $keyToGroup[$k] = $g;
            }
        }

        $hlOdds = ['net_1' => true, 'net_2' => true, 'net_3' => true];
        foreach (array_keys($odds) as $k) {
            if ($k === 'period') {
                continue;
            }
            $row = [];
            if (!empty($hlOdds[$k])) {
                $row['hl'] = true;
            }
            if (isset($keyToGroup[$k])) {
                $row['group'] = $keyToGroup[$k];
            }
            if ($row !== []) {
                $meta['odds'][$k] = $row;
            }
        }
        foreach (array_keys($opiu) as $k) {
            if ($k === 'period') {
                continue;
            }
            $row = [];
            if ($k === 'ebit') {
                $row['hl'] = true;
            }
            if (isset($keyToGroup[$k])) {
                $row['group'] = $keyToGroup[$k];
            }
            if ($row !== []) {
                $meta['opiu'][$k] = $row;
            }
        }
        foreach (array_keys($balance) as $k) {
            if ($k === 'period') {
                continue;
            }
            $row = [];
            if ($k === 'assets_total' || $k === 'liab_total') {
                $row['hl'] = true;
            }
            if (isset($keyToGroup[$k])) {
                $row['group'] = $keyToGroup[$k];
            }
            if ($row !== []) {
                $meta['balance'][$k] = $row;
            }
        }
        foreach (array_keys($kanal) as $k) {
            if ($k === 'period') {
                continue;
            }
            $row = [];
            if ($k === 'roce_ebit') {
                $row['hl'] = true;
            }
            if ($row !== []) {
                $meta['kanal'][$k] = $row;
            }
        }
        if ($funds !== null) {
            foreach (array_keys($funds) as $k) {
                if ($k === 'period') {
                    continue;
                }
                $row = [];
                if ($k === 'fund_credit_h' || $k === 'fund_debit_h' || $k === 'fund_total_h') {
                    $row['hl'] = true;
                }
                if (isset($keyToGroup[$k])) {
                    $row['group'] = $keyToGroup[$k];
                }
                if ($row !== []) {
                    $meta['funds'][$k] = $row;
                }
            }
        }
        // accounts link group 3
        if (isset($keyToGroup['acc_sum'])) {
            $meta['accounts']['acc_sum'] = ['group' => $keyToGroup['acc_sum']];
        }
        unset($opiu, $kanal);
        return $meta;
    }

    /**
     * Keep only display period columns; rewrite period header labels.
     *
     * @param array<string,array<int,float|string>> $matrix
     * @param list<int> $displayNs
     * @param array<int,string> $periodLabelsAll
     * @return array<string,array<int,float|string>>
     */
    private static function sliceMatrix(array $matrix, array $displayNs, array $periodLabelsAll): array
    {
        $keep = array_fill_keys($displayNs, true);
        $out = [];
        foreach ($matrix as $rowKey => $row) {
            $nr = [];
            if (array_key_exists(0, $row)) {
                $nr[0] = $row[0];
            }
            foreach ($row as $n => $v) {
                if ($n === 0) {
                    continue;
                }
                if (!isset($keep[$n])) {
                    continue;
                }
                $nr[$n] = $v;
            }
            if ($rowKey === 'period') {
                foreach ($displayNs as $n) {
                    $nr[$n] = $periodLabelsAll[$n];
                }
            }
            $out[$rowKey] = $nr;
        }
        return $out;
    }

    /**
     * Drill-down URLs for numeric cells (ledger moves / fund movements).
     *
     * @param list<int> $displayNs
     * @param array<int,int> $periodYears
     * @param array<int,int> $periodMonths
     * @param array<int,array{id:int,name:string,odds_type:int,exclude_pl:int,is_div:int}> $typesIns
     * @param array<int,array{id:int,name:string,odds_type:int,exclude_pl:int,is_div:int}> $typesOuts
     * @return array<string,array<string,array<int,string>>>
     */
    private static function buildCellLinks(
        array $displayNs,
        array $periodYears,
        array $periodMonths,
        int $yearMode,
        array $typesIns,
        array $typesOuts,
        bool $hasFunds
    ): array {
        $links = ['odds' => [], 'funds' => []];
        $typeIds = array_unique(array_merge(array_keys($typesIns), array_keys($typesOuts)));

        foreach ($typeIds as $tid) {
            $rowKey = 't_' . $tid;
            foreach ($displayNs as $n) {
                $y = (int)($periodYears[$n] ?? 0);
                if ($y < 1) {
                    continue;
                }
                $q = ['type_id' => $tid, 'year' => $y];
                $m = (int)($periodMonths[$n] ?? 0);
                if ($yearMode > 0 && $m > 0) {
                    $q['month'] = $m;
                }
                $links['odds'][$rowKey][$n] = '/ledger/moves?' . http_build_query($q);
            }
        }

        if ($hasFunds) {
            $fundMap = [
                'fund_os_end' => ['fund_type' => 1],
                'fund_os_start' => ['fund_type' => 1],
                'fund_from_cap' => ['fund_type' => 1, 'type_id' => 1],
                'fund_from_credit' => ['fund_type' => 1, 'type_id' => 1],
                'fund_amort_spend' => ['fund_type' => 1, 'type_id' => 1],
                'fund_amort' => ['fund_type' => 1, 'type_id' => 2],
                'fund_amort_accrued' => ['fund_type' => 1, 'type_id' => 2],
                'fund_total_pay' => ['type_id' => 2],
                'fund_reval' => ['type_id' => 3],
                'fund_deval' => ['type_id' => 4],
                'fund_amort_destroy' => ['type_id' => 5],
                'fund_debit_accrued' => ['fund_type' => 2, 'type_id' => 2],
                'fund_debit_spend' => ['fund_type' => 2, 'type_id' => 1],
                'fund_debit_end' => ['fund_type' => 2],
            ];
            foreach ($fundMap as $rowKey => $base) {
                foreach ($displayNs as $n) {
                    $y = (int)($periodYears[$n] ?? 0);
                    if ($y < 1) {
                        continue;
                    }
                    $q = $base;
                    $q['year'] = $y;
                    $q['yearE'] = $y;
                    $m = (int)($periodMonths[$n] ?? 0);
                    if ($yearMode > 0 && $m > 0) {
                        $q['month'] = $m;
                        $q['monthE'] = $m;
                    }
                    $links['funds'][$rowKey][$n] = '/fund-movements?' . http_build_query($q);
                }
            }
        }

        return $links;
    }
}
