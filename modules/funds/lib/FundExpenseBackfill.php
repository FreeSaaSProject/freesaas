<?php
declare(strict_types=1);

/**
 * Posts CAPUSE for expense DPS whose type is already mapped to a fund but which were saved
 * before the map existed. Idempotent: a DPS that already points at a fund movement is skipped.
 */
final class FundExpenseBackfill
{
    public const LIMIT = 5000;

    /**
     * @param array{from?:string,to?:string,dry_run?:bool} $opts
     *   from/to are YYYY-MM-DD, half-open [from, to). Empty bound means no limit on that side.
     * @return array<string,mixed>
     */
    public static function run(array $opts = []): array
    {
        $dry = !empty($opts['dry_run']);
        $from = self::bound((string)($opts['from'] ?? ''), false);
        $to = self::bound((string)($opts['to'] ?? ''), true);
        $empty = [
            'ok' => true, 'dry_run' => $dry, 'from' => $from, 'to' => $to,
            'created' => 0, 'summ' => 0.0, 'truncated' => false, 'by_fund' => [],
        ];
        if (!FundTypeMapRepository::tableReady() || !Database::tableExists('base_account_moves')) {
            $empty['ok'] = false;
            return $empty;
        }
        $where = 'm.DELETED = 0 AND m.DIRECTION < 0 AND m.ID_MOVEMENT_LINKED = 0 AND m.ID_FUND_MOVEMENT = 0 AND map.ENABLED > 0';
        $params = [];
        if ($from > 0) {
            $where .= ' AND m.DT >= ?';
            $params[] = $from;
        }
        if ($to > 0) {
            $where .= ' AND m.DT < ?';
            $params[] = $to;
        }
        $typeId = (int)($opts['type_id'] ?? 0);
        if ($typeId > 0) {
            $where .= ' AND m.ID_TYPE = ?';
            $params[] = $typeId;
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM base_account_moves m
             INNER JOIN base_fund_type_map map ON map.ID_TYPE = m.ID_TYPE WHERE $where"
        );
        $st->execute($params);
        $count = (int)$st->fetchColumn();
        $st = $pdo->prepare(
            "SELECT m.ID, m.SUMM, map.ID_FUND FROM base_account_moves m
             INNER JOIN base_fund_type_map map ON map.ID_TYPE = m.ID_TYPE
             WHERE $where ORDER BY m.DT ASC, m.ID ASC LIMIT " . (self::LIMIT + 1)
        );
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $truncated = count($rows) > self::LIMIT;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::LIMIT);
        }
        $byFund = [];
        $summ = 0.0;
        foreach ($rows as $r) {
            $fid = (int)$r['ID_FUND'];
            $amount = round(abs((float)$r['SUMM']), 2);
            $summ += $amount;
            if (!isset($byFund[$fid])) {
                $fund = FundsRepository::allRows()[$fid] ?? null;
                $byFund[$fid] = ['fund_id' => $fid, 'name' => (string)($fund['name'] ?? ''), 'n' => 0, 'summ' => 0.0];
            }
            $byFund[$fid]['n']++;
            $byFund[$fid]['summ'] = round($byFund[$fid]['summ'] + $amount, 2);
            if (!$dry) {
                FundsLedgerHooks::applyForMove((int)$r['ID']);
            }
        }
        return [
            'ok' => true,
            'dry_run' => $dry,
            'from' => $from,
            'to' => $to,
            'candidates' => $count,
            'created' => count($rows),
            'summ' => round($summ, 2),
            'truncated' => $truncated,
            'by_fund' => array_values($byFund),
            'http' => 200,
        ];
    }

    /** @return int unix start, or exclusive end when $endOfDay; 0 when empty/invalid */
    private static function bound(string $date, bool $endOfDay): int
    {
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return 0;
        }
        $ts = strtotime($date . ' 00:00:00');
        if ($ts === false) {
            return 0;
        }
        return $endOfDay ? $ts + 86400 : $ts;
    }
}
