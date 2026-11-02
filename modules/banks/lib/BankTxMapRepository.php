<?php
declare(strict_types=1);

final class BankTxMapRepository
{
    public static function ready(): bool
    {
        return Database::tableExists('base_bank_tx_map');
    }

    /**
     * Map key scoped to account link so same bank tx id on two own accounts
     * (transfer) does not collapse. Also accepts bare legacy ids.
     */
    public static function mapKey(string $externalTxId, int $linkId): string
    {
        $externalTxId = trim($externalTxId);
        if ($externalTxId === '') {
            return '';
        }
        if ($linkId > 0 && strpos($externalTxId, 'L' . $linkId . ':') !== 0) {
            return 'L' . $linkId . ':' . $externalTxId;
        }
        return $externalTxId;
    }

    /**
     * Stable fallback when the bank API omits a real transaction id.
     * Do not hash the whole raw payload (status flips → new id → duplicate move).
     *
     * @param array{direction?:int,amount?:float,dt?:int,purpose?:string,counterparty_inn?:string,counterparty_name?:string} $parts
     */
    public static function fingerprint(array $parts): string
    {
        $stable = [
            'd' => (int)($parts['direction'] ?? 0) >= 0 ? 1 : -1,
            'a' => round((float)($parts['amount'] ?? 0), 2),
            't' => (int)($parts['dt'] ?? 0),
            'p' => trim((string)($parts['purpose'] ?? '')),
            'i' => trim((string)($parts['counterparty_inn'] ?? '')),
            'n' => trim((string)($parts['counterparty_name'] ?? '')),
        ];
        return 'fp:' . sha1((string)json_encode($stable, JSON_UNESCAPED_UNICODE));
    }

    public static function moveIdFor(string $provider, string $externalTxId, int $linkId = 0): int
    {
        if ($externalTxId === '' || !self::ready()) {
            return 0;
        }
        $keys = [$externalTxId];
        if ($linkId > 0) {
            $scoped = self::mapKey($externalTxId, $linkId);
            if ($scoped !== '' && $scoped !== $externalTxId) {
                array_unshift($keys, $scoped);
            }
        }
        $st = Database::pdo()->prepare(
            'SELECT ID_MOVE FROM base_bank_tx_map
             WHERE PROVIDER = ? AND EXTERNAL_TX_ID = ? LIMIT 1'
        );
        foreach ($keys as $key) {
            $st->execute([$provider, $key]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                continue;
            }
            $moveId = (int)($row['ID_MOVE'] ?? 0);
            // >0 linked; 0 = claimed by parallel sync — treat as present (-1).
            return $moveId > 0 ? $moveId : -1;
        }
        return 0;
    }

    /**
     * Atomically reserve (PROVIDER, EXTERNAL_TX_ID) before creating a ledger move.
     * Returns the stored map key on success, '' if already claimed/linked.
     */
    public static function tryClaim(
        string $provider,
        string $externalTxId,
        int $linkId,
        int $connectionId
    ): string {
        if ($externalTxId === '' || !self::ready()) {
            return '';
        }
        $key = self::mapKey($externalTxId, $linkId);
        if ($key === '') {
            return '';
        }
        if (self::moveIdFor($provider, $externalTxId, $linkId) !== 0) {
            return '';
        }
        try {
            Database::pdo()->prepare(
                'INSERT INTO base_bank_tx_map
                 (PROVIDER, EXTERNAL_TX_ID, ID_MOVE, ID_LINK, ID_CONNECTION, DT_CREATE)
                 VALUES (?, ?, 0, ?, ?, ?)'
            )->execute([$provider, $key, $linkId, $connectionId, time()]);
            return $key;
        } catch (PDOException $e) {
            return '';
        }
    }

    public static function bindMove(string $provider, string $mapKey, int $moveId): void
    {
        if ($mapKey === '' || $moveId < 1 || !self::ready()) {
            return;
        }
        Database::pdo()->prepare(
            'UPDATE base_bank_tx_map SET ID_MOVE = ? WHERE PROVIDER = ? AND EXTERNAL_TX_ID = ?'
        )->execute([$moveId, $provider, $mapKey]);
    }

    public static function releaseClaim(string $provider, string $mapKey): void
    {
        if ($mapKey === '' || !self::ready()) {
            return;
        }
        Database::pdo()->prepare(
            'DELETE FROM base_bank_tx_map
             WHERE PROVIDER = ? AND EXTERNAL_TX_ID = ? AND ID_MOVE = 0'
        )->execute([$provider, $mapKey]);
    }

    /**
     * Drop unbound claims (ID_MOVE=0) for a link so the next file import can
     * re-evaluate skip-regex / match window after settings change or rollback.
     */
    public static function releaseUnboundForLink(string $provider, int $linkId): int
    {
        if ($provider === '' || $linkId < 1 || !self::ready()) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'DELETE FROM base_bank_tx_map
             WHERE PROVIDER = ? AND ID_LINK = ? AND ID_MOVE = 0'
        );
        $st->execute([$provider, $linkId]);
        return (int)$st->rowCount();
    }

    /**
     * Bare external_tx ids already seen for this link (strip L{id}: scope prefix).
     *
     * @return array<string,true> set for O(1) lookup
     */
    public static function knownExternalIdSet(string $provider, int $linkId): array
    {
        if ($provider === '' || $linkId < 1 || !self::ready()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT EXTERNAL_TX_ID FROM base_bank_tx_map
             WHERE PROVIDER = ? AND ID_LINK = ?'
        );
        $st->execute([$provider, $linkId]);
        $prefix = 'L' . $linkId . ':';
        $plen = strlen($prefix);
        $out = [];
        while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $raw = (string)($row['EXTERNAL_TX_ID'] ?? '');
            if ($raw === '') {
                continue;
            }
            if (strpos($raw, $prefix) === 0) {
                $raw = substr($raw, $plen);
            }
            if ($raw !== '') {
                $out[$raw] = true;
            }
        }
        return $out;
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
        $key = self::mapKey($externalTxId, $linkId);
        Database::pdo()->prepare(
            'INSERT INTO base_bank_tx_map
             (PROVIDER, EXTERNAL_TX_ID, ID_MOVE, ID_LINK, ID_CONNECTION, DT_CREATE)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ID_MOVE = VALUES(ID_MOVE), ID_LINK = VALUES(ID_LINK)'
        )->execute([$provider, $key, $moveId, $linkId, $connectionId, time()]);
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
     * Manual ДПС candidates: same account, amount, direction; bank day (±slack) matches DT or DT_PROMISED.
     * Skips moves already in tx_map. Ordered by closest date to bankDt.
     *
     * @return list<array{id:int,descr:string,dt:int,dt_promised:int}>
     */
    public static function findMatchCandidates(
        int $accountId,
        float $amount,
        int $direction,
        int $bankDt,
        int $limit = 5,
        int $daySlack = 0
    ): array {
        if ($accountId < 1 || $amount <= 0 || $bankDt < 1 || !Database::tableExists('base_account_moves')) {
            return [];
        }
        $dir = $direction >= 0 ? 1 : -1;
        $daySlack = max(0, min(31, $daySlack));
        $dayStart = mktime(
            0,
            0,
            0,
            (int)date('n', $bankDt),
            (int)date('j', $bankDt) - $daySlack,
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
            (int)date('j', $bankDt) + $daySlack + 1,
            (int)date('Y', $bankDt)
        );
        if ($dayEnd === false) {
            $dayEnd = $dayStart + (86400 * ($daySlack * 2 + 1));
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
        $sql .= ' ORDER BY LEAST(
                    ABS(CAST(m.DT AS SIGNED) - ?),
                    CASE WHEN m.DT_PROMISED > 0 THEN ABS(CAST(m.DT_PROMISED AS SIGNED) - ?) ELSE 2147483647 END
                 ) ASC, m.ID ASC
                 LIMIT ' . $limit;
        $params[] = $bankDt;
        $params[] = $bankDt;
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

    /** Point an existing map row at another move (orphan → manual ДПС). */
    public static function retargetMove(string $provider, int $fromMoveId, int $toMoveId): bool
    {
        if ($provider === '' || $fromMoveId < 1 || $toMoveId < 1 || !self::ready()) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_bank_tx_map SET ID_MOVE = ?
             WHERE PROVIDER = ? AND ID_MOVE = ?'
        );
        $st->execute([$toMoveId, $provider, $fromMoveId]);
        return $st->rowCount() > 0;
    }

    /**
     * Bank-sourced moves still on default type («неразобрано»).
     */
    public static function countUnclassified(): int
    {
        if (!self::ready() || !Database::tableExists('base_account_moves')) {
            return 0;
        }
        $defaultType = BanksConfig::defaultMoveTypeId();
        if ($defaultType < 1) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_account_moves m
             INNER JOIN base_bank_tx_map t ON t.ID_MOVE = m.ID
             WHERE m.DELETED = 0 AND m.ID_TYPE = ?'
        );
        $st->execute([$defaultType]);
        return (int)$st->fetchColumn();
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
