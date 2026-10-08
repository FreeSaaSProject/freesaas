<?php
declare(strict_types=1);

final class BankTxMapRepository
{
    public static function ready(): bool
    {
        return Database::tableExists('base_bank_tx_map');
    }

    public static function moveIdFor(string $provider, string $externalTxId): int
    {
        if ($externalTxId === '' || !self::ready()) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID_MOVE FROM base_bank_tx_map
             WHERE PROVIDER = ? AND EXTERNAL_TX_ID = ? LIMIT 1'
        );
        $st->execute([$provider, $externalTxId]);
        return (int)$st->fetchColumn();
    }

    public static function remember(
        string $provider,
        string $externalTxId,
        int $moveId,
        int $linkId,
        int $connectionId
    ): void {
        if ($externalTxId === '' || $moveId < 1 || !self::ready()) {
            return;
        }
        Database::pdo()->prepare(
            'INSERT INTO base_bank_tx_map
             (PROVIDER, EXTERNAL_TX_ID, ID_MOVE, ID_LINK, ID_CONNECTION, DT_CREATE)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ID_MOVE = VALUES(ID_MOVE)'
        )->execute([$provider, $externalTxId, $moveId, $linkId, $connectionId, time()]);
    }

    public static function isMoveLinked(int $moveId): bool
    {
        if ($moveId < 1 || !self::ready()) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT 1 FROM base_bank_tx_map WHERE ID_MOVE = ? LIMIT 1'
        );
        $st->execute([$moveId]);
        return (bool)$st->fetchColumn();
    }

    /**
     * Manual ДПС candidates: same account, amount, direction; bank day matches DT or DT_PROMISED.
     * Skips moves already in tx_map. Returns up to $limit rows (id ASC).
     *
     * @return list<array{id:int,descr:string,dt:int,dt_promised:int}>
     */
    public static function findMatchCandidates(
        int $accountId,
        float $amount,
        int $direction,
        int $bankDt,
        int $limit = 5
    ): array {
        if ($accountId < 1 || $amount <= 0 || $bankDt < 1 || !Database::tableExists('base_account_moves')) {
            return [];
        }
        $dir = $direction >= 0 ? 1 : -1;
        $dayStart = mktime(
            0,
            0,
            0,
            (int)date('n', $bankDt),
            (int)date('j', $bankDt),
            (int)date('Y', $bankDt)
        );
        if ($dayStart === false) {
            return [];
        }
        $dayEnd = mktime(
            0,
            0,
            0,
            (int)date('n', $bankDt),
            (int)date('j', $bankDt) + 1,
            (int)date('Y', $bankDt)
        );
        if ($dayEnd === false) {
            $dayEnd = $dayStart + 86400;
        }
        $sum = round($amount, 2);
        $limit = max(1, min(20, $limit));
        $sql = 'SELECT m.ID AS id, m.DESCR AS descr, m.DT AS dt, m.DT_PROMISED AS dt_promised
             FROM base_account_moves m
             WHERE m.ID_ACCOUNT = ?
               AND m.DELETED = 0
               AND m.DIRECTION = ?
               AND ABS(m.SUMM - ?) < 0.005
               AND (
                    (m.DT >= ? AND m.DT < ?)
                    OR (m.DT_PROMISED > 0 AND m.DT_PROMISED >= ? AND m.DT_PROMISED < ?)
               )';
        $params = [$accountId, $dir, $sum, $dayStart, $dayEnd, $dayStart, $dayEnd];
        if (self::ready()) {
            $sql .= ' AND NOT EXISTS (
                SELECT 1 FROM base_bank_tx_map map WHERE map.ID_MOVE = m.ID
             )';
        }
        $sql .= ' ORDER BY m.ID ASC LIMIT ' . $limit;
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'descr' => (string)($r['descr'] ?? ''),
                'dt' => (int)$r['dt'],
                'dt_promised' => (int)$r['dt_promised'],
            ];
        }
        return $out;
    }

    /**
     * @param list<int> $moveIds
     * @return array<int,array{provider:string,link_id:int}>
     */
    public static function byMoveIds(array $moveIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $moveIds))));
        if ($ids === [] || !self::ready()) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID_MOVE AS move_id, PROVIDER AS provider, ID_LINK AS link_id
             FROM base_bank_tx_map WHERE ID_MOVE IN ($in)"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['move_id']] = [
                'provider' => (string)$r['provider'],
                'link_id' => (int)$r['link_id'],
            ];
        }
        return $out;
    }
}
