<?php
declare(strict_types=1);

final class LaundryMovesRepository
{
    public static function balance(int $accountId, ?int $itemTypeId = null): int
    {
        if ($accountId < 1 || !Database::tableExists('base_laundry_moves')) {
            return 0;
        }
        $sql = 'SELECT COALESCE(SUM(CASE WHEN DIRECTION > 0 THEN SUMM WHEN DIRECTION < 0 THEN -SUMM ELSE 0 END), 0)
                FROM base_laundry_moves WHERE ID_ACCOUNT = ? AND DELETED = 0';
        $params = [$accountId];
        if ($itemTypeId !== null && $itemTypeId > 0) {
            $sql .= ' AND ID_ITEM_TYPE = ?';
            $params[] = $itemTypeId;
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    /** @return array<int,array<int,int>> accountId => typeId => balance */
    public static function balanceMatrix(): array
    {
        if (!Database::tableExists('base_laundry_moves')) {
            return [];
        }
        $sql = 'SELECT ID_ACCOUNT AS aid, ID_ITEM_TYPE AS tid,
                       COALESCE(SUM(CASE WHEN DIRECTION > 0 THEN SUMM WHEN DIRECTION < 0 THEN -SUMM ELSE 0 END), 0) AS bal
                FROM base_laundry_moves WHERE DELETED = 0
                GROUP BY ID_ACCOUNT, ID_ITEM_TYPE';
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['aid']][(int)$r['tid']] = (int)$r['bal'];
        }
        return $out;
    }

    public static function hasAnyMoves(): bool
    {
        if (!Database::tableExists('base_laundry_moves')) {
            return false;
        }
        return (int)Database::pdo()->query(
            'SELECT 1 FROM base_laundry_moves WHERE DELETED = 0 LIMIT 1'
        )->fetchColumn() === 1;
    }

    /**
     * @param array{id_account?:int|null,id_item_type?:int|null,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_laundry_moves')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['DELETED = 0'];
        $params = [];
        if (!empty($opts['id_account'])) {
            $where[] = 'ID_ACCOUNT = ?';
            $params[] = (int)$opts['id_account'];
        }
        if (!empty($opts['id_item_type'])) {
            $where[] = 'ID_ITEM_TYPE = ?';
            $params[] = (int)$opts['id_item_type'];
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_laundry_moves WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, ID_ACCOUNT AS id_account, ID_ACCOUNT_TO AS id_account_to,
                       ID_ITEM_TYPE AS id_item_type, ID_MOVEMENT_LINKED AS id_movement_linked,
                       ID_SHIPMENT AS id_shipment, ID_RETURN AS id_return,
                       DIRECTION AS direction, SUMM AS summ, DESCR AS descr, WEIGHT AS weight, DT AS dt
                FROM base_laundry_moves WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /**
     * Create linked transfer for one item type. Caller manages outer transaction when batching.
     *
     * @return array{from_id:int,to_id:int}
     */
    public static function insertTransferPair(
        PDO $pdo,
        int $fromAcc,
        int $toAcc,
        int $typeId,
        int $summ,
        string $descr,
        float $weight,
        int $dt,
        int $userId,
        int $shipmentId = 0,
        int $returnId = 0
    ): array {
        $summ = max(1, $summ);
        $now = time();
        $ins = $pdo->prepare(
            'INSERT INTO base_laundry_moves
             (ID_ACCOUNT, ID_ACCOUNT_TO, ID_ITEM_TYPE, ID_USER_CREATE, ID_USER_UPDATE, ID_MOVEMENT_LINKED,
              ID_SHIPMENT, ID_RETURN, DIRECTION, SUMM, DESCR, WEIGHT, DT, DT_CREATE, DT_UPDATE, DELETED)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $ins->execute([
            $fromAcc, $toAcc, $typeId, $userId, $userId, $shipmentId, $returnId,
            -1, $summ, mb_substr($descr, 0, 255), $weight, $dt, $now, $now,
        ]);
        $fromId = (int)$pdo->lastInsertId();
        $pairDescr = t('laundry.move.pair_descr', ['id' => $fromId, 'descr' => $descr]);
        $ins->execute([
            $toAcc, 0, $typeId, $userId, $userId, $shipmentId, $returnId,
            1, $summ, mb_substr($pairDescr, 0, 255), $weight, $dt, $now, $now,
        ]);
        $toId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE base_laundry_moves SET ID_MOVEMENT_LINKED = ? WHERE ID = ?')->execute([$toId, $fromId]);
        $pdo->prepare('UPDATE base_laundry_moves SET ID_MOVEMENT_LINKED = ? WHERE ID = ?')->execute([$fromId, $toId]);
        return ['from_id' => $fromId, 'to_id' => $toId];
    }

    /**
     * Single-leg adjust (inventory). Positive qty = credit, negative = debit.
     */
    public static function insertAdjust(
        PDO $pdo,
        int $accountId,
        int $typeId,
        int $signedQty,
        string $descr,
        int $dt,
        int $userId
    ): int {
        if ($signedQty === 0) {
            return 0;
        }
        $dir = $signedQty > 0 ? 1 : -1;
        $summ = abs($signedQty);
        $now = time();
        $pdo->prepare(
            'INSERT INTO base_laundry_moves
             (ID_ACCOUNT, ID_ACCOUNT_TO, ID_ITEM_TYPE, ID_USER_CREATE, ID_USER_UPDATE, ID_MOVEMENT_LINKED,
              ID_SHIPMENT, ID_RETURN, DIRECTION, SUMM, DESCR, WEIGHT, DT, DT_CREATE, DT_UPDATE, DELETED)
             VALUES (?, 0, ?, ?, ?, 0, 0, 0, ?, ?, ?, 0, ?, ?, ?, 0)'
        )->execute([
            $accountId, $typeId, $userId, $userId, $dir, $summ,
            mb_substr($descr, 0, 255), $dt, $now, $now,
        ]);
        return (int)$pdo->lastInsertId();
    }
}
