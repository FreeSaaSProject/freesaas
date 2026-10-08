<?php
declare(strict_types=1);

/**
 * Monthly liquid-assets report: deposits + current cash + broker snapshots.
 */
final class LiquidAssetsReport
{
    public static function fmtMoney(float $v): string
    {
        return number_format(round($v, 2), 2, '.', ' ');
    }

    public static function isDepoAccountName(string $name): bool
    {
        $up = function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name);
        if (function_exists('mb_strpos')) {
            return (mb_strpos($up, 'ДЕПО') !== false) || (mb_strpos($up, 'DEPO') !== false);
        }

        return (strpos($up, 'ДЕПО') !== false) || (strpos($up, 'DEPO') !== false);
    }

    /** @param array<string,mixed> $t */
    public static function depoKey(array $t): string
    {
        $open = (isset($t['open']) && $t['open'] !== '') ? (string)$t['open'] : 'noopen';
        $summ = isset($t['open_summ']) ? round((float)$t['open_summ'], 2) : 0.0;

        return $open . '|' . sprintf('%.2f', $summ);
    }

    /** @return array<int,string> id => NAME */
    public static function accountNames(): array
    {
        if (!Database::tableExists('base_accounts')) {
            return [];
        }
        $out = [];
        foreach (Database::pdo()->query('SELECT ID, NAME FROM base_accounts')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['ID']] = (string)$r['NAME'];
        }

        return $out;
    }

    public static function accountBalance(int $idAccount, int $asOfTs): float
    {
        if ($idAccount < 1 || !Database::tableExists('base_account_moves')) {
            return 0.0;
        }
        $st = Database::pdo()->prepare(
            'SELECT SUM(SUMM * DIRECTION) AS B FROM base_account_moves
             WHERE ID_ACCOUNT = ? AND IFNULL(DELETED, 0) = 0 AND DT <= ?'
        );
        $st->execute([$idAccount, $asOfTs]);

        return (float)$st->fetchColumn();
    }

    /**
     * @return array{list:list<array<string,mixed>>,depo_ids:list<int>}
     */
    public static function collectDeposits(int $asOfTs, int $monthStart = 0): array
    {
        $accounts = self::accountNames();
        $depoAccIds = [];
        foreach ($accounts as $aid => $name) {
            if (self::isDepoAccountName($name)) {
                $depoAccIds[(int)$aid] = 1;
            }
        }
        $list = [];
        if ($depoAccIds === [] || !Database::tableExists('base_account_moves')) {
            return ['list' => $list, 'depo_ids' => []];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_ACCOUNT, DESCR, DT, SUMM FROM base_account_moves
             WHERE IFNULL(DELETED, 0) = 0 AND DT <= ? AND DESCR LIKE ?
             ORDER BY DT DESC, ID DESC LIMIT 3000'
        );
        $st->execute([$asOfTs, '%[депо:%']);
        $byKey = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $acc = (int)$row['ID_ACCOUNT'];
            if (!isset($depoAccIds[$acc])) {
                continue;
            }
            $tags = DepoTagParser::parse((string)$row['DESCR']);
            if ($tags === []) {
                continue;
            }
            $t = $tags[0];
            if ($t['open_ts'] > 0 && $t['open_ts'] > $asOfTs) {
                continue;
            }
            if ($monthStart > 0 && $t['close_ts'] > 0 && $t['close_ts'] < $monthStart) {
                continue;
            }
            $key = $acc . '|' . self::depoKey($t);
            if (isset($byKey[$key])) {
                continue;
            }
            $t['id_account'] = $acc;
            $t['id_move'] = (int)$row['ID'];
            $t['bank'] = isset($accounts[$acc]) ? $accounts[$acc] : ('#' . $acc);
            $t['from_dt'] = (int)$row['DT'];
            $t['depo_key'] = self::depoKey($t);
            $byKey[$key] = $t;
        }
        $byAcc = [];
        foreach ($byKey as $t) {
            $acc = (int)$t['id_account'];
            if (!isset($byAcc[$acc])) {
                $byAcc[$acc] = [];
            }
            $byAcc[$acc][] = $t;
        }
        ksort($byAcc);
        foreach ($byAcc as $acc => $rows) {
            $hasActive = false;
            foreach ($rows as $t) {
                $cts = isset($t['close_ts']) ? (int)$t['close_ts'] : 0;
                if ($cts <= 0 || $cts > $asOfTs) {
                    $hasActive = true;
                    break;
                }
            }
            if ($hasActive) {
                $filtered = [];
                foreach ($rows as $t) {
                    $cts = isset($t['close_ts']) ? (int)$t['close_ts'] : 0;
                    if ($cts > 0 && $cts <= $asOfTs) {
                        continue;
                    }
                    $filtered[] = $t;
                }
                $rows = $filtered;
            }
            if ($rows === []) {
                continue;
            }
            usort($rows, static function (array $a, array $b): int {
                $oa = isset($a['open_ts']) ? (int)$a['open_ts'] : 0;
                $ob = isset($b['open_ts']) ? (int)$b['open_ts'] : 0;
                if ($oa !== $ob) {
                    return ($oa < $ob) ? -1 : 1;
                }

                return ((int)$a['id_move'] < (int)$b['id_move']) ? -1 : 1;
            });
            $bal = self::accountBalance((int)$acc, $asOfTs);
            $first = true;
            foreach ($rows as $t) {
                $t['balance'] = $bal;
                $t['show_balance'] = $first;
                $first = false;
                $list[] = $t;
            }
        }

        return ['list' => $list, 'depo_ids' => array_map('intval', array_keys($depoAccIds))];
    }

    /** @param list<int>|null $depoIds */
    public static function cashOtherTotal(int $asOfTs, ?array $depoIds = null): float
    {
        $accounts = self::accountNames();
        if ($depoIds === null) {
            $depoIds = [];
            foreach ($accounts as $aid => $name) {
                if (self::isDepoAccountName($name)) {
                    $depoIds[] = (int)$aid;
                }
            }
        }
        $depoMap = [];
        foreach ($depoIds as $id) {
            $depoMap[(int)$id] = 1;
        }
        if (!Database::tableExists('base_account_moves')) {
            return 0.0;
        }
        $sum = 0.0;
        $st = Database::pdo()->prepare(
            'SELECT ID_ACCOUNT, SUM(SUMM * DIRECTION) AS B FROM base_account_moves
             WHERE IFNULL(DELETED, 0) = 0 AND DT <= ? GROUP BY ID_ACCOUNT'
        );
        $st->execute([$asOfTs]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $acc = (int)$row['ID_ACCOUNT'];
            if (isset($depoMap[$acc])) {
                continue;
            }
            if (!isset($accounts[$acc])) {
                continue;
            }
            $sum += (float)$row['B'];
        }

        return $sum;
    }

    /**
     * @param list<int>|null $depoIds
     * @return array{
     *   in_current:float,out_ops:float,out_invest:float,
     *   in_by_cat:array<int,float>,out_by_cat:array<int,float>
     * }
     */
    public static function monthCashFlows(int $monthStart, int $monthEnd, ?array $depoIds = null): array
    {
        $out = [
            'in_current' => 0.0,
            'out_ops' => 0.0,
            'out_invest' => 0.0,
            'in_by_cat' => [1 => 0.0, 2 => 0.0, 3 => 0.0],
            'out_by_cat' => [1 => 0.0, 2 => 0.0, 3 => 0.0],
        ];
        $accounts = self::accountNames();
        if ($depoIds === null) {
            $depoIds = [];
            foreach ($accounts as $aid => $name) {
                if (self::isDepoAccountName($name)) {
                    $depoIds[] = (int)$aid;
                }
            }
        }
        $depoMap = [];
        foreach ($depoIds as $id) {
            $depoMap[(int)$id] = 1;
        }
        $typeCat = [];
        if (Database::tableExists('base_account_move_types')) {
            foreach (Database::pdo()->query('SELECT ID, ID_TYPE FROM base_account_move_types')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $td) {
                $typeCat[(int)$td['ID']] = (int)$td['ID_TYPE'];
            }
        }
        if (!Database::tableExists('base_account_moves')) {
            return $out;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID_ACCOUNT, ID_TYPE, DIRECTION, SUMM FROM base_account_moves
             WHERE IFNULL(DELETED, 0) = 0 AND IFNULL(UNCONFIRMED, 0) = 0
               AND IFNULL(ID_MOVEMENT_LINKED, 0) = 0
               AND DT >= ? AND DT <= ?'
        );
        $st->execute([$monthStart, $monthEnd]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $acc = (int)$row['ID_ACCOUNT'];
            $dir = (int)$row['DIRECTION'];
            $summ = (float)$row['SUMM'];
            $tid = (int)$row['ID_TYPE'];
            $cat = $typeCat[$tid] ?? 0;
            if ($dir > 0) {
                if (isset($depoMap[$acc])) {
                    continue;
                }
                if (!isset($accounts[$acc])) {
                    continue;
                }
                $out['in_current'] += $summ;
                if (isset($out['in_by_cat'][$cat])) {
                    $out['in_by_cat'][$cat] += $summ;
                }
            } elseif ($dir < 0) {
                if ($cat === 2) {
                    $out['out_invest'] += $summ;
                } else {
                    $out['out_ops'] += $summ;
                }
                if (isset($out['out_by_cat'][$cat])) {
                    $out['out_by_cat'][$cat] += $summ;
                }
            }
        }
        $out['in_current'] = round($out['in_current'], 2);
        $out['out_ops'] = round($out['out_ops'], 2);
        $out['out_invest'] = round($out['out_invest'], 2);
        foreach ($out['in_by_cat'] as $k => $v) {
            $out['in_by_cat'][$k] = round($v, 2);
        }
        foreach ($out['out_by_cat'] as $k => $v) {
            $out['out_by_cat'][$k] = round($v, 2);
        }

        return $out;
    }

    /**
     * @return array{y:int,m:int,ym:string,month_start:int,month_end:int,prev_ym:string,next_ym:string,title:string,is_current:bool}
     */
    public static function parseYm(?string $ym): array
    {
        $ym = $ym !== null ? trim($ym) : date('Y-m');
        if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $ymM)) {
            $ym = date('Y-m');
            preg_match('/^(\d{4})-(\d{2})$/', $ym, $ymM);
        }
        $y = (int)$ymM[1];
        $m = (int)$ymM[2];
        if ($m < 1 || $m > 12) {
            $m = (int)date('n');
            $y = (int)date('Y');
            $ym = sprintf('%04d-%02d', $y, $m);
        }
        $monthStart = (int)mktime(0, 0, 0, $m, 1, $y);
        $monthEnd = (int)mktime(23, 59, 59, $m + 1, 0, $y);
        $monthKey = 'liquid_assets.report.month.' . $m;
        $monthName = t($monthKey);
        if ($monthName === $monthKey) {
            $monthName = (string)$m;
        }

        return [
            'y' => $y,
            'm' => $m,
            'ym' => $ym,
            'month_start' => $monthStart,
            'month_end' => $monthEnd,
            'prev_ym' => date('Y-m', (int)mktime(0, 0, 0, $m - 1, 1, $y)),
            'next_ym' => date('Y-m', (int)mktime(0, 0, 0, $m + 1, 1, $y)),
            'title' => $monthName . ' ' . $y,
            'is_current' => (sprintf('%04d-%02d', $y, $m) === date('Y-m')),
        ];
    }

    /**
     * Full report payload for view / agent JSON.
     *
     * @return array<string,mixed>
     */
    public static function build(string $ym): array
    {
        $period = self::parseYm($ym);
        $monthStart = $period['month_start'];
        $monthEnd = $period['month_end'];
        $isCurrent = $period['is_current'];
        $asOfBalance = $isCurrent ? min(time(), $monthEnd) : $monthEnd;

        $depoData = self::collectDeposits($asOfBalance, $monthStart);
        $depoList = $depoData['list'];
        $depoSum = 0.0;
        foreach ($depoList as $d) {
            if (!empty($d['show_balance'])) {
                $depoSum += (float)$d['balance'];
            }
        }
        $cashOther = self::cashOtherTotal($asOfBalance, $depoData['depo_ids']);
        $monthFlows = self::monthCashFlows($monthStart, $asOfBalance, $depoData['depo_ids']);

        $fundNames = [];
        if (TinvestSyncService::fundsEnabled() && class_exists('FundsRepository', false)) {
            $fundNames = FundsRepository::names();
        }

        $tinvestRows = TinvestAccountsRepository::all();
        $brokerBlocks = [];
        $brokerTotal = 0.0;
        $monthChanges = [];
        $fundsInBrokerTotal = [];

        foreach ($tinvestRows as $tr) {
            $idT = (int)$tr['id'];
            $idFund = (int)$tr['fund_id'];
            $label = TinvestAccountsRepository::label($tr, $fundNames);
            $fundName = '';
            if ($idFund > 0 && isset($fundNames[$idFund])) {
                $fundName = ltrim((string)$fundNames[$idFund], '— ');
            }
            $snap = BrokerSnapshotsRepository::findLatestByTinvest($idT, $monthEnd);
            $positions = [];
            $total = 0.0;
            $source = 'none';
            $fundSummary = null;
            $deltaVal = null;
            $hasPrevSnap = false;

            if ($snap) {
                $source = 'snapshot';
                $total = (float)$snap['TOTAL_VALUE'];
                $positions = BrokerSnapshotsRepository::positionsForSnapshot((int)$snap['ID']);
                $brokerTotal += $total;
                $prevSnap = BrokerSnapshotsRepository::findPrevious($idT, $monthStart);
                if ($prevSnap) {
                    $hasPrevSnap = true;
                    $deltaVal = $total - (float)$prevSnap['TOTAL_VALUE'];
                }
            } elseif ($idFund > 0 && TinvestSyncService::fundsEnabled()) {
                $source = 'fund';
                $asOfFund = $isCurrent ? min(time(), $monthEnd) : $monthEnd;
                $fundSummary = TinvestSyncService::fundMonthSummary($idFund, $monthStart, $asOfFund);
                $total = $fundSummary['value_end'];
                $deltaVal = $fundSummary['delta'];
                $hasPrevSnap = true;
                if (!isset($fundsInBrokerTotal[$idFund])) {
                    $fundsInBrokerTotal[$idFund] = true;
                    $brokerTotal += $total;
                }
            }

            $opsSummary = null;
            if ($source === 'snapshot' && $snap && !empty($snap['RAW_JSON'])) {
                $rj = json_decode((string)$snap['RAW_JSON'], true);
                if (is_array($rj) && isset($rj['ops_month']['summary']) && is_array($rj['ops_month']['summary'])) {
                    $opsSummary = $rj['ops_month']['summary'];
                    if (isset($rj['ops_month']['ym']) && $rj['ops_month']['ym'] !== date('Y-m', $monthStart)) {
                        $opsSummary = null;
                    }
                }
            }

            $monthChanges[] = [
                'label' => $label,
                'fund_name' => $fundName,
                'id_fund' => $idFund,
                'source' => $source,
                'total' => $total,
                'delta_val' => $deltaVal,
                'has_prev_snap' => $hasPrevSnap,
                'snap_dt' => $snap ? (int)$snap['DT'] : 0,
                'ops_summary' => $opsSummary,
                'fund_summary' => $fundSummary,
            ];

            $brokerBlocks[] = [
                'label' => $label,
                'fund' => $fundName,
                'id_fund' => $idFund,
                'total' => $total,
                'snap' => $snap,
                'positions' => $positions,
                'accounts' => ($source === 'snapshot')
                    ? BrokerSnapshotsRepository::accountsFromSnapshot($snap, $positions)
                    : [],
                'id_tinvest' => $idT,
                'source' => $source,
                'fund_summary' => $fundSummary,
                'delta_val' => $deltaVal,
            ];
        }

        $grand = $depoSum + $cashOther + $brokerTotal;

        return [
            'period' => $period,
            'as_of' => $asOfBalance,
            'deposits' => $depoList,
            'depo_sum' => $depoSum,
            'cash_other' => $cashOther,
            'broker_total' => $brokerTotal,
            'grand' => $grand,
            'month_flows' => $monthFlows,
            'broker_blocks' => $brokerBlocks,
            'month_changes' => $monthChanges,
            'tinvest_count' => count($tinvestRows),
            'funds_on' => TinvestSyncService::fundsEnabled(),
            'api_stats' => TinvestApiClient::stats(),
        ];
    }

    /**
     * Slim report for agents: drop RAW_JSON / heavy snapshot blobs, keep totals and positions.
     *
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function forAgent(array $report): array
    {
        $blocks = [];
        foreach ($report['broker_blocks'] ?? [] as $bb) {
            $snap = $bb['snap'] ?? null;
            $snapLite = null;
            if (is_array($snap)) {
                $snapLite = [
                    'id' => (int)($snap['ID'] ?? 0),
                    'dt' => (int)($snap['DT'] ?? 0),
                    'total_value' => (float)($snap['TOTAL_VALUE'] ?? 0),
                    'source' => (string)($snap['SOURCE'] ?? ''),
                    'id_tinvest' => (int)($snap['ID_TINVEST'] ?? 0),
                    'id_fund' => (int)($snap['ID_FUND'] ?? 0),
                ];
            }
            $positions = [];
            foreach ($bb['positions'] ?? [] as $p) {
                $meta = [];
                if (!empty($p['META'])) {
                    $decoded = json_decode((string)$p['META'], true);
                    if (is_array($decoded)) {
                        if (isset($decoded['expectedYield'])) {
                            $meta['expectedYield'] = $decoded['expectedYield'];
                        }
                        if (!empty($decoded['bond_note'])) {
                            $meta['bond_note'] = $decoded['bond_note'];
                        }
                    }
                }
                $positions[] = [
                    'ticker' => (string)($p['TICKER'] ?? ''),
                    'figi' => (string)($p['FIGI'] ?? ''),
                    'name' => (string)($p['NAME'] ?? ''),
                    'asset_class' => (string)($p['ASSET_CLASS'] ?? ''),
                    'qty' => (float)($p['QTY'] ?? 0),
                    'price' => (float)($p['PRICE'] ?? 0),
                    'value' => (float)($p['VALUE'] ?? 0),
                    'avg_price' => (float)($p['AVG_PRICE'] ?? 0),
                    'meta' => $meta,
                ];
            }
            $accounts = [];
            foreach ($bb['accounts'] ?? [] as $acc) {
                $accounts[] = [
                    'id' => (string)($acc['id'] ?? ''),
                    'title' => (string)($acc['title'] ?? ''),
                    'total' => (float)($acc['total'] ?? 0),
                    'positions' => $acc['positions'] ?? [],
                ];
            }
            $blocks[] = [
                'label' => $bb['label'] ?? '',
                'fund' => $bb['fund'] ?? '',
                'id_fund' => (int)($bb['id_fund'] ?? 0),
                'id_tinvest' => (int)($bb['id_tinvest'] ?? 0),
                'total' => (float)($bb['total'] ?? 0),
                'source' => (string)($bb['source'] ?? ''),
                'delta_val' => $bb['delta_val'] ?? null,
                'snap' => $snapLite,
                'positions' => $positions,
                'accounts' => $accounts,
                'fund_summary' => $bb['fund_summary'] ?? null,
            ];
        }

        return [
            'period' => $report['period'] ?? null,
            'as_of' => (int)($report['as_of'] ?? 0),
            'depo_sum' => (float)($report['depo_sum'] ?? 0),
            'cash_other' => (float)($report['cash_other'] ?? 0),
            'broker_total' => (float)($report['broker_total'] ?? 0),
            'grand' => (float)($report['grand'] ?? 0),
            'deposits' => $report['deposits'] ?? [],
            'month_flows' => $report['month_flows'] ?? [],
            'broker_blocks' => $blocks,
            'month_changes' => $report['month_changes'] ?? [],
            'tinvest_count' => (int)($report['tinvest_count'] ?? 0),
            'funds_on' => !empty($report['funds_on']),
            'api_stats' => $report['api_stats'] ?? null,
        ];
    }
}
