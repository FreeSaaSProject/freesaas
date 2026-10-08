<?php
declare(strict_types=1);

final class BrokerSnapshotsRepository
{
    public static function tableExists(): bool
    {
        return Database::tableExists('base_broker_snapshots')
            && Database::tableExists('base_broker_positions');
    }

    public static function findLatestByTinvest(int $idTinvest, int $asOfTs): ?array
    {
        if ($idTinvest < 1 || !self::tableExists()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_TINVEST, ID_FUND, DT, TOTAL_VALUE, SOURCE, RAW_JSON, ID_USER_CREATE, DT_CREATE
             FROM base_broker_snapshots
             WHERE ID_TINVEST = ? AND DT <= ?
             ORDER BY DT DESC LIMIT 1'
        );
        $st->execute([$idTinvest, $asOfTs]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function findPrevious(int $idTinvest, int $beforeTs): ?array
    {
        if ($idTinvest < 1 || !self::tableExists()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_TINVEST, ID_FUND, DT, TOTAL_VALUE, SOURCE, RAW_JSON
             FROM base_broker_snapshots
             WHERE ID_TINVEST = ? AND DT < ?
             ORDER BY DT DESC LIMIT 1'
        );
        $st->execute([$idTinvest, $beforeTs]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function findInMonth(int $idTinvest, int $monthStart, int $monthEnd): ?array
    {
        if ($idTinvest < 1 || !self::tableExists()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_TINVEST, ID_FUND, DT, TOTAL_VALUE, SOURCE, RAW_JSON
             FROM base_broker_snapshots
             WHERE ID_TINVEST = ? AND DT >= ? AND DT <= ?
             ORDER BY DT DESC LIMIT 1'
        );
        $st->execute([$idTinvest, $monthStart, $monthEnd]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function positionsForSnapshot(int $snapshotId): array
    {
        if ($snapshotId < 1 || !self::tableExists()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_SNAPSHOT, TICKER, FIGI, NAME, ASSET_CLASS, QTY, PRICE, VALUE, AVG_PRICE, META
             FROM base_broker_positions WHERE ID_SNAPSHOT = ? ORDER BY ASSET_CLASS, TICKER'
        );
        $st->execute([$snapshotId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Delete snapshot(s) for calendar day of $snapDt (and their positions). */
    public static function deleteForDay(int $idTinvest, int $snapDt): void
    {
        if ($idTinvest < 1 || !self::tableExists()) {
            return;
        }
        $dayStart = (int)mktime(0, 0, 0, (int)date('n', $snapDt), (int)date('j', $snapDt), (int)date('Y', $snapDt));
        $dayEnd = $dayStart + 86400 - 1;
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_broker_snapshots WHERE ID_TINVEST = ? AND DT >= ? AND DT <= ? ORDER BY DT DESC'
        );
        $st->execute([$idTinvest, $dayStart, $dayEnd]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($ids as $sid) {
            $sid = (int)$sid;
            Database::pdo()->prepare('DELETE FROM base_broker_positions WHERE ID_SNAPSHOT = ?')->execute([$sid]);
            Database::pdo()->prepare('DELETE FROM base_broker_snapshots WHERE ID = ?')->execute([$sid]);
        }
    }

    /**
     * @param list<array<string,mixed>> $positions
     * @param array<string,mixed> $rawPayload
     */
    public static function insertSnapshot(
        int $idTinvest,
        int $idFund,
        int $snapDt,
        float $total,
        array $rawPayload,
        array $positions,
        int $userId
    ): int {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_broker_snapshots
             (ID_TINVEST, ID_FUND, DT, TOTAL_VALUE, SOURCE, RAW_JSON, ID_USER_CREATE, DT_CREATE)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $idTinvest,
            $idFund,
            $snapDt,
            $total,
            'api',
            (string)json_encode($rawPayload, JSON_UNESCAPED_UNICODE),
            $userId,
            time(),
        ]);
        $sid = (int)Database::pdo()->lastInsertId();
        $ins = Database::pdo()->prepare(
            'INSERT INTO base_broker_positions
             (ID_SNAPSHOT, TICKER, FIGI, NAME, ASSET_CLASS, QTY, PRICE, VALUE, AVG_PRICE, META)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($positions as $pos) {
            $ins->execute([
                $sid,
                (string)($pos['ticker'] ?? ''),
                (string)($pos['figi'] ?? ''),
                (string)($pos['name'] ?? ''),
                (string)($pos['asset_class'] ?? 'other'),
                (float)($pos['qty'] ?? 0),
                (float)($pos['price'] ?? 0),
                (float)($pos['value'] ?? 0),
                (float)($pos['avg_price'] ?? 0),
                (string)json_encode($pos['meta'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);
        }

        return $sid;
    }

    /**
     * Split snapshot into per-account blocks for UI.
     *
     * @param array<string,mixed>|null $snap
     * @param list<array<string,mixed>> $dbPositions
     * @return list<array{id:string,title:string,total:float,positions:list<array<string,mixed>>}>
     */
    public static function accountsFromSnapshot(?array $snap, array $dbPositions = []): array
    {
        $out = [];
        $nameByFigi = [];
        $nameByTicker = [];
        $noteByFigi = [];
        foreach ($dbPositions as $p) {
            if (!empty($p['FIGI']) && !empty($p['NAME'])) {
                $nameByFigi[(string)$p['FIGI']] = (string)$p['NAME'];
            }
            if (!empty($p['TICKER']) && !empty($p['NAME'])) {
                $nameByTicker[(string)$p['TICKER']] = (string)$p['NAME'];
            }
            if (!empty($p['FIGI']) && !empty($p['META'])) {
                $meta = json_decode((string)$p['META'], true);
                if (is_array($meta) && !empty($meta['bond_note'])) {
                    $noteByFigi[(string)$p['FIGI']] = (string)$meta['bond_note'];
                }
            }
        }
        $titles = [];
        $rj = null;
        if ($snap && !empty($snap['RAW_JSON'])) {
            $rj = json_decode((string)$snap['RAW_JSON'], true);
        }
        if (is_array($rj) && isset($rj['accounts']) && is_array($rj['accounts'])) {
            foreach ($rj['accounts'] as $an) {
                if (preg_match('/^(\S+)(?:\s+[«"](.+?)[»"])?/u', (string)$an, $am)) {
                    if (isset($am[2]) && $am[2] !== '') {
                        $titles[$am[1]] = $am[2];
                    }
                }
            }
        }
        if (is_array($rj) && isset($rj['portfolios']) && is_array($rj['portfolios']) && count($rj['portfolios'])) {
            $accN = 0;
            foreach ($rj['portfolios'] as $accId => $pdata) {
                $accN++;
                $parsed = TinvestApiClient::parsePortfolio(is_array($pdata) ? $pdata : []);
                $rows = [];
                foreach ($parsed['positions'] as $pos) {
                    $name = $pos['name'];
                    if (isset($nameByFigi[$pos['figi']])) {
                        $name = $nameByFigi[$pos['figi']];
                    } elseif (isset($nameByTicker[$pos['ticker']])) {
                        $name = $nameByTicker[$pos['ticker']];
                    }
                    $pl = null;
                    if (isset($pos['meta']['expectedYield'])) {
                        $pl = (float)$pos['meta']['expectedYield'];
                    }
                    $note = '';
                    if (isset($noteByFigi[$pos['figi']])) {
                        $note = $noteByFigi[$pos['figi']];
                    } elseif (!empty($pos['meta']['bond_note'])) {
                        $note = (string)$pos['meta']['bond_note'];
                    }
                    $rows[] = [
                        'TICKER' => $pos['ticker'],
                        'ASSET_CLASS' => $pos['asset_class'],
                        'NAME' => $name,
                        'NOTE' => $note,
                        'QTY' => $pos['qty'],
                        'PRICE' => $pos['price'],
                        'AVG_PRICE' => $pos['avg_price'],
                        'VALUE' => $pos['value'],
                        'PL' => $pl,
                    ];
                }
                TinvestApiClient::sortPositionRows($rows);
                $title = isset($titles[$accId]) ? $titles[$accId] : '';
                if ($title === '') {
                    $title = (count($rj['portfolios']) > 1)
                        ? ('#' . $accN)
                        : 'Broker';
                }
                $out[] = [
                    'id' => (string)$accId,
                    'title' => $title,
                    'total' => round($parsed['total'], 2),
                    'positions' => $rows,
                ];
            }

            return $out;
        }
        if ($dbPositions !== []) {
            $rows = [];
            $sumVal = 0.0;
            foreach ($dbPositions as $p) {
                $pl = null;
                $note = '';
                if (!empty($p['META'])) {
                    $meta = json_decode((string)$p['META'], true);
                    if (is_array($meta) && isset($meta['expectedYield'])) {
                        $pl = (float)$meta['expectedYield'];
                    }
                    if (is_array($meta) && !empty($meta['bond_note'])) {
                        $note = (string)$meta['bond_note'];
                    }
                }
                $sumVal += (float)$p['VALUE'];
                $rows[] = [
                    'TICKER' => $p['TICKER'],
                    'ASSET_CLASS' => $p['ASSET_CLASS'],
                    'NAME' => $p['NAME'],
                    'NOTE' => $note,
                    'QTY' => $p['QTY'],
                    'PRICE' => $p['PRICE'],
                    'AVG_PRICE' => $p['AVG_PRICE'],
                    'VALUE' => $p['VALUE'],
                    'PL' => $pl,
                ];
            }
            TinvestApiClient::sortPositionRows($rows);
            $total = $snap ? (float)$snap['TOTAL_VALUE'] : $sumVal;
            $out[] = ['id' => '', 'title' => 'All', 'total' => $total, 'positions' => $rows];
        }

        return $out;
    }
}
