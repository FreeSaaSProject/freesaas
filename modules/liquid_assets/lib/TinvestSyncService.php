<?php
declare(strict_types=1);

/**
 * Sync T-Invest portfolios into broker snapshots + optional fund REVAL/DEVAL.
 */
final class TinvestSyncService
{
    public static function snapshotDt(int $monthEnd): int
    {
        $now = time();

        return ($monthEnd > $now) ? $now : $monthEnd;
    }

    public static function fundsEnabled(): bool
    {
        return ModuleManager::isEnabled('funds')
            && Database::tableExists('base_funds')
            && Database::tableExists('base_fund_movements');
    }

    public static function lastFundValue(int $idFund, int $asOfTs = 0, int $excludeId = 0): float
    {
        if ($idFund < 1 || !self::fundsEnabled()) {
            return 0.0;
        }
        $sql = 'SELECT VALUE FROM base_fund_movements WHERE ID_FUND = ? AND VALUE > 0';
        $params = [$idFund];
        if ($asOfTs > 0) {
            $sql .= ' AND DT_AMORT_START <= ?';
            $params[] = $asOfTs;
        }
        if ($excludeId > 0) {
            $sql .= ' AND ID != ?';
            $params[] = $excludeId;
        }
        $sql .= ' ORDER BY DT_AMORT_START DESC, ID DESC LIMIT 1';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();

        return $v !== false ? (float)$v : 0.0;
    }

    /**
     * @return array{value_start:float,value_end:float,delta:float,by_type:array<int,float>,net_signed:float,n_moves:int}
     */
    public static function fundMonthSummary(int $idFund, int $monthStart, int $monthEnd): array
    {
        $out = [
            'value_start' => 0.0,
            'value_end' => 0.0,
            'delta' => 0.0,
            'by_type' => [],
            'net_signed' => 0.0,
            'n_moves' => 0,
        ];
        if ($idFund < 1 || !self::fundsEnabled()) {
            return $out;
        }
        $out['value_start'] = self::lastFundValue($idFund, $monthStart > 0 ? $monthStart - 1 : 0);
        $out['value_end'] = self::lastFundValue($idFund, $monthEnd);
        $out['delta'] = round($out['value_end'] - $out['value_start'], 2);
        $st = Database::pdo()->prepare(
            'SELECT ID_TYPE, DIRECTION, SUMM FROM base_fund_movements
             WHERE ID_FUND = ? AND DT_AMORT_START >= ? AND DT_AMORT_START <= ?'
        );
        $st->execute([$idFund, $monthStart, $monthEnd]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $tid = (int)$row['ID_TYPE'];
            $summ = (float)$row['SUMM'];
            $dir = (int)$row['DIRECTION'];
            if (!isset($out['by_type'][$tid])) {
                $out['by_type'][$tid] = 0.0;
            }
            $out['by_type'][$tid] += $summ;
            $out['net_signed'] += $dir * $summ;
            $out['n_moves']++;
        }
        $out['net_signed'] = round($out['net_signed'], 2);
        foreach ($out['by_type'] as $k => $v) {
            $out['by_type'][$k] = round($v, 2);
        }

        return $out;
    }

    public static function fundMoveTypeLabel(int $idType): string
    {
        if (class_exists('FundMovementsRepository', false)) {
            return FundMovementsRepository::typeLabel($idType);
        }
        $key = 'funds.moves.type.' . $idType;
        $label = t($key);

        return $label !== $key ? $label : ('#' . $idType);
    }

    /** @return array<string,mixed>|null */
    public static function findMonthReval(int $idFund, int $monthStart, int $monthEnd): ?array
    {
        if ($idFund < 1 || !self::fundsEnabled()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_fund_movements
             WHERE ID_FUND = ? AND ID_TYPE IN (3, 4)
               AND DT_AMORT_START >= ? AND DT_AMORT_START <= ?
             ORDER BY DT_AMORT_START DESC, ID DESC LIMIT 1'
        );
        $st->execute([$idFund, $monthStart, $monthEnd]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array{id_type:int,direction:int,summ:float,summ_infl:float,value:int}|null
     */
    public static function buildRevalFields(float $oldValue, float $newValue): ?array
    {
        $oldValue = round($oldValue, 2);
        $newValue = round($newValue, 2);
        $diff = round($newValue - $oldValue, 2);
        if (abs($diff) < 0.01) {
            return null;
        }
        if ($diff > 0) {
            return [
                'id_type' => 3,
                'direction' => -1,
                'summ' => abs($diff),
                'summ_infl' => abs($diff),
                'value' => (int)round($newValue),
            ];
        }

        return [
            'id_type' => 4,
            'direction' => 1,
            'summ' => abs($diff),
            'summ_infl' => 0.0,
            'value' => (int)round($newValue),
        ];
    }

    /**
     * @param array<string,mixed>|null $existingRow
     * @return array{ok:bool,skipped:bool,changed:bool,msg:string,old:float,new:float}
     */
    public static function upsertFundReval(
        int $idFund,
        float $newValue,
        int $userId,
        string $label,
        int $dt,
        ?array $existingRow = null
    ): array {
        if (!self::fundsEnabled()) {
            return [
                'ok' => false,
                'skipped' => true,
                'changed' => false,
                'msg' => t('liquid_assets.sync.funds_off'),
                'old' => 0.0,
                'new' => round($newValue, 2),
            ];
        }
        $excludeId = $existingRow ? (int)$existingRow['ID'] : 0;
        $oldValue = self::lastFundValue($idFund, $dt, $excludeId);
        $fields = self::buildRevalFields($oldValue, $newValue);
        if ($fields === null) {
            return [
                'ok' => true,
                'skipped' => true,
                'changed' => false,
                'msg' => 'VALUE unchanged (' . round($newValue, 2) . ')',
                'old' => $oldValue,
                'new' => round($newValue, 2),
            ];
        }
        $fundType = 1;
        $rf = Database::pdo()->prepare('SELECT TYPE FROM base_funds WHERE ID = ? LIMIT 1');
        $rf->execute([$idFund]);
        $fr = $rf->fetch(PDO::FETCH_ASSOC);
        if ($fr) {
            $fundType = (int)$fr['TYPE'];
        }
        $pdo = Database::pdo();
        if ($existingRow) {
            $eid = (int)$existingRow['ID'];
            $st = $pdo->prepare(
                'UPDATE base_fund_movements SET
                 ID_TYPE = ?, DIRECTION = ?, SUMM = ?, SUMM_OUT_INFL = ?, VALUE = ?,
                 NAME = ?, DESCR = ?, DT_AMORT_START = ?, DT_AMORT_END = ?,
                 ID_USER_UPDATE = ?, DT_UPDATE = ?
                 WHERE ID = ?'
            );
            $st->execute([
                $fields['id_type'], $fields['direction'], $fields['summ'], $fields['summ_infl'], $fields['value'],
                $label, $label, $dt, $dt, $userId, time(), $eid,
            ]);
            $kind = $fields['id_type'] === 3 ? 'REVAL' : 'DEVAL';

            return [
                'ok' => true,
                'skipped' => false,
                'changed' => true,
                'msg' => 'updated ' . $kind . ' #' . $eid . ' ' . $fields['summ']
                    . ' (VALUE ' . $oldValue . '→' . $newValue . ')',
                'old' => $oldValue,
                'new' => round($newValue, 2),
            ];
        }
        $st = $pdo->prepare(
            'INSERT INTO base_fund_movements SET
             ID_FUND = ?, ID_TYPE = ?, ID_FUND_TYPE = ?, ID_USER_CREATE = ?, ID_USER_UPDATE = ?,
             DIRECTION = ?, SUMM = ?, SUMM_OUT_CAP = 0, SUMM_OUT_FUND = 0, SUMM_OUT_INFL = ?,
             SUMM_OUT_DESTROY = 0, NAME = ?, DESCR = ?, DT_AMORT_START = ?, DT_AMORT_END = ?,
             DT_CREATE = ?, DT_UPDATE = ?, AMORT_MONTHS = 0, VALUE = ?'
        );
        $now = time();
        $st->execute([
            $idFund, $fields['id_type'], $fundType, $userId, $userId,
            $fields['direction'], $fields['summ'], $fields['summ_infl'],
            $label, $label, $dt, $dt, $now, $now, $fields['value'],
        ]);
        $kind = $fields['id_type'] === 3 ? 'REVAL' : 'DEVAL';

        return [
            'ok' => true,
            'skipped' => false,
            'changed' => true,
            'msg' => $kind . ' ' . $fields['summ'] . ' (VALUE ' . $oldValue . '→' . $newValue . ')',
            'old' => $oldValue,
            'new' => round($newValue, 2),
        ];
    }

    /**
     * @return array{
     *   ok:bool,msg:string,snapshot_id:int,total:float,asset_x:float,fund_y:float,
     *   need_confirm:bool,id_fund:int,id_tinvest:int,existing_reval_id:int,label:string,snap_dt:int
     * }
     */
    public static function syncAccount(int $idTinvest, int $userId, int $monthStart, int $monthEnd): array
    {
        $empty = static function (string $msg) use ($idTinvest): array {
            return [
                'ok' => false,
                'msg' => $msg,
                'snapshot_id' => 0,
                'total' => 0.0,
                'asset_x' => 0.0,
                'fund_y' => 0.0,
                'need_confirm' => false,
                'id_fund' => 0,
                'id_tinvest' => $idTinvest,
                'existing_reval_id' => 0,
                'label' => '',
                'snap_dt' => 0,
            ];
        };
        $row = TinvestAccountsRepository::find($idTinvest);
        if (!$row) {
            return $empty('T-Invest #' . $idTinvest . ' not found');
        }
        $token = trim($row['token']);
        $idFund = (int)$row['fund_id'];
        $name = TinvestAccountsRepository::label($row);
        if ($token === '') {
            return $empty('Empty TOKEN for «' . $name . '»');
        }
        $accList = TinvestApiClient::listAccounts($token);
        if (!$accList['ok']) {
            return $empty($name . ': GetAccounts — ' . $accList['error']);
        }
        $accounts = [];
        foreach ($accList['accounts'] as $a) {
            if ($a['id'] === '') {
                continue;
            }
            $st = strtoupper($a['status']);
            if ($st !== '' && strpos($st, 'CLOSED') !== false) {
                continue;
            }
            $accounts[] = $a;
        }
        if ($accounts === []) {
            return $empty($name . ': no open accounts' . TinvestApiClient::accountsHint($token));
        }
        $total = 0.0;
        $posLists = [];
        $rawParts = [];
        $accNames = [];
        foreach ($accounts as $a) {
            $api = TinvestApiClient::post(
                'tinkoff.public.invest.api.contract.v1.OperationsService/GetPortfolio',
                $token,
                ['accountId' => $a['id'], 'currency' => 'RUB']
            );
            if (!$api['ok']) {
                return $empty($name . ': GetPortfolio ' . $a['id'] . ' — ' . $api['error']);
            }
            $parsed = TinvestApiClient::parsePortfolio(is_array($api['data']) ? $api['data'] : []);
            TinvestApiClient::enrichPositionNames($token, $parsed['positions']);
            $total += $parsed['total'];
            $posLists[] = $parsed['positions'];
            $rawParts[$a['id']] = $api['data'];
            $accNames[] = $a['id'] . ($a['name'] !== '' ? ' «' . $a['name'] . '»' : '');
        }
        $total = round($total, 2);
        $positions = TinvestApiClient::mergePositions($posLists);
        $snapDt = self::snapshotDt($monthEnd);
        $accIds = [];
        foreach ($accounts as $a) {
            $accIds[] = $a['id'];
        }
        $opsMonth = TinvestApiClient::fetchOperationsSummary($token, $accIds, $monthStart, $monthEnd);
        BrokerSnapshotsRepository::deleteForDay($idTinvest, $snapDt);
        $rawPayload = [
            'accounts' => $accNames,
            'portfolios' => $rawParts,
            'ops_month' => [
                'from' => $monthStart,
                'to' => $monthEnd,
                'ym' => date('Y-m', $monthStart),
                'summary' => $opsMonth,
            ],
        ];
        $sid = BrokerSnapshotsRepository::insertSnapshot(
            $idTinvest,
            $idFund,
            $snapDt,
            $total,
            $rawPayload,
            $positions,
            $userId
        );
        $existing = ($idFund > 0 && self::fundsEnabled())
            ? self::findMonthReval($idFund, $monthStart, $monthEnd)
            : null;
        $excludeId = $existing ? (int)$existing['ID'] : 0;
        $fundY = $idFund > 0 ? self::lastFundValue($idFund, $snapDt, $excludeId) : 0.0;
        if ($existing) {
            $fundY = (float)$existing['VALUE'];
        }
        $need = false;
        if ($idFund > 0 && self::fundsEnabled()) {
            $baseForDiff = self::lastFundValue($idFund, $snapDt, $excludeId);
            $need = self::buildRevalFields($baseForDiff, $total) !== null;
        }
        $msg = 'OK: ' . $name . ' — ' . count($accounts) . ' acc., total '
            . number_format($total, 2, '.', ' ') . '; snapshot #' . $sid
            . ' @ ' . date('d.m.Y H:i', $snapDt)
            . ' (' . implode(', ', $accNames) . ')';

        return [
            'ok' => true,
            'msg' => $msg,
            'snapshot_id' => $sid,
            'total' => $total,
            'asset_x' => $total,
            'fund_y' => round($fundY, 2),
            'need_confirm' => $need,
            'id_fund' => $idFund,
            'id_tinvest' => $idTinvest,
            'existing_reval_id' => $excludeId,
            'label' => $name,
            'snap_dt' => $snapDt,
        ];
    }

    /**
     * @return array{ok:bool,msgs:list<string>,pending:list<array<string,mixed>>,lines:list<string>}
     */
    public static function syncAll(int $userId, int $monthStart, int $monthEnd): array
    {
        $ids = TinvestAccountsRepository::ids();
        if ($ids === []) {
            return [
                'ok' => false,
                'msgs' => [t('liquid_assets.sync.no_rows')],
                'pending' => [],
                'lines' => [],
            ];
        }
        $lines = [];
        $pending = [];
        $allOk = true;
        foreach ($ids as $idSync) {
            $r = self::syncAccount($idSync, $userId, $monthStart, $monthEnd);
            $lines[] = $r['msg'];
            if (!$r['ok']) {
                $allOk = false;
            } elseif (!empty($r['need_confirm'])) {
                $pending[] = $r;
            }
        }

        return ['ok' => $allOk, 'msgs' => $lines, 'pending' => $pending, 'lines' => $lines];
    }

    /**
     * @return array{ok:bool,msgs:list<string>}
     */
    public static function applyFundRevals(int $userId, int $monthStart, int $monthEnd): array
    {
        if (!self::fundsEnabled()) {
            return ['ok' => false, 'msgs' => [t('liquid_assets.sync.funds_off')]];
        }
        $snapDt = self::snapshotDt($monthEnd);
        $msgs = [];
        foreach (TinvestAccountsRepository::all() as $tr) {
            $idT = (int)$tr['id'];
            $idFund = (int)$tr['fund_id'];
            $label = TinvestAccountsRepository::label($tr);
            if ($idFund <= 0) {
                $msgs[] = $label . ': fund not linked — skip';
                continue;
            }
            $snap = BrokerSnapshotsRepository::findInMonth($idT, $monthStart, $monthEnd);
            if (!$snap) {
                $msgs[] = $label . ': no snapshot this month — sync first';
                continue;
            }
            $total = (float)$snap['TOTAL_VALUE'];
            $existing = self::findMonthReval($idFund, $monthStart, $monthEnd);
            $dt = $existing ? (int)$existing['DT_AMORT_START'] : $snapDt;
            if ($dt < $monthStart || $dt > $monthEnd) {
                $dt = $snapDt;
            }
            $mvLabel = 'T-Invest sync ' . date('d.m.Y', $dt) . ' (' . $label . ')';
            $rv = self::upsertFundReval($idFund, $total, $userId, $mvLabel, $dt, $existing);
            $msgs[] = $label . ': ' . $rv['msg'];
            if (!$rv['ok']) {
                return ['ok' => false, 'msgs' => $msgs];
            }
        }

        return ['ok' => true, 'msgs' => $msgs];
    }
}
