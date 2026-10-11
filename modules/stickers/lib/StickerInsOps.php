<?php
declare(strict_types=1);

/**
 * Oper group actions for sticker ins (revision / movement / reprint).
 */
final class StickerInsOps
{
    public static function appendLog(string $log, int $userId, string $msg): string
    {
        return StickerInsRepository::appendLog($log, $userId, $msg);
    }

    /** @return array<int,true> sticker ids with open weight outs */
    public static function openOutMap(): array
    {
        if (!Database::tableExists('base_good_sticker_outs')) {
            return [];
        }
        $st = Database::pdo()->query(
            'SELECT ID_STICKER FROM base_good_sticker_outs
             WHERE WEIGHT_BEFORE > 0 AND (WEIGHT_AFTER = 0 OR WEIGHT_AFTER IS NULL)'
        );
        $out = [];
        foreach ($st ? ($st->fetchAll(PDO::FETCH_COLUMN) ?: []) : [] as $id) {
            $out[(int)$id] = true;
        }
        return $out;
    }

    /**
     * Send selected activated stickers to reprint (DT_REPRINT).
     *
     * @param list<int> $ids
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function markReprint(array $ids, int $userId): array
    {
        $done = 0;
        $skipped = [];
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'UPDATE base_good_sticker_ins SET LOG = ?, DT_REPRINT = ? WHERE ID = ?'
        );
        foreach ($ids as $id) {
            $id = (int)$id;
            $row = StickerInsRepository::find($id);
            if ($row === null) {
                continue;
            }
            if ((int)$row['dt_print'] < 1) {
                $skipped[] = ['id' => $id, 'reason' => t('stickers.bulk.err.not_activated')];
                continue;
            }
            if ((int)$row['dt_reprint'] > 0) {
                $skipped[] = ['id' => $id, 'reason' => t('stickers.bulk.err.already_reprint')];
                continue;
            }
            $log = self::appendLog((string)$row['log'], $userId, t('stickers.bulk.log.reprint'));
            $st->execute([$log, time(), $id]);
            $done++;
        }
        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * Enable revision on all activated unused stickers of a stock (oper do=4).
     *
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function enableRevisionForStock(int $stockId, int $userId): array
    {
        return self::setFlagForStock($stockId, $userId, 'DT_REVISION', true, t('stickers.bulk.log.revi_on'));
    }

    /**
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function disableRevisionForStock(int $stockId, int $userId): array
    {
        return self::clearFlagForStock($stockId, $userId, 'DT_REVISION', t('stickers.bulk.log.revi_off'));
    }

    /**
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function enableMovementForStock(int $stockId, int $userId): array
    {
        return self::setFlagForStock($stockId, $userId, 'DT_MOVEMENT', true, t('stickers.bulk.log.move_on'));
    }

    /**
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function disableMovementForStock(int $stockId, int $userId): array
    {
        return self::clearFlagForStock($stockId, $userId, 'DT_MOVEMENT', t('stickers.bulk.log.move_off'));
    }

    public static function stockHasRevision(int $stockId = 0): bool
    {
        $sql = 'SELECT 1 FROM base_good_sticker_ins WHERE DT_REVISION > 0';
        $params = [];
        if ($stockId > 0) {
            $sql .= ' AND ID_STOCK = ?';
            $params[] = $stockId;
        }
        $sql .= ' LIMIT 1';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return (bool)$st->fetchColumn();
    }

    public static function stockHasMovement(int $stockId = 0): bool
    {
        $sql = 'SELECT 1 FROM base_good_sticker_ins WHERE DT_MOVEMENT > 0';
        $params = [];
        if ($stockId > 0) {
            $sql .= ' AND ID_STOCK = ?';
            $params[] = $stockId;
        }
        $sql .= ' LIMIT 1';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return (bool)$st->fetchColumn();
    }

    public static function hasActivatedAvailable(): bool
    {
        $st = Database::pdo()->query(
            'SELECT 1 FROM base_good_sticker_ins WHERE DT_PRINT > 0 AND QTYU_IN > QTYU_OUT LIMIT 1'
        );
        return $st && (bool)$st->fetchColumn();
    }

    /**
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    private static function setFlagForStock(
        int $stockId,
        int $userId,
        string $flagCol,
        bool $requireActivated,
        string $logMsg
    ): array {
        if ($stockId < 1 || !in_array($flagCol, ['DT_REVISION', 'DT_MOVEMENT'], true)) {
            return ['done' => 0, 'skipped' => []];
        }
        $open = self::openOutMap();
        $sql = 'SELECT ID AS id, LOG AS log FROM base_good_sticker_ins
                WHERE ID_STOCK = ? AND QTYU_OUT < QTYU_IN';
        if ($requireActivated) {
            $sql .= ' AND DT_PRINT > 0';
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute([$stockId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $done = 0;
        $skipped = [];
        $up = Database::pdo()->prepare(
            "UPDATE base_good_sticker_ins SET LOG = ?, `$flagCol` = ? WHERE ID = ?"
        );
        $now = time();
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (isset($open[$id])) {
                $skipped[] = ['id' => $id, 'reason' => t('stickers.bulk.err.open_out')];
                continue;
            }
            $log = self::appendLog((string)$row['log'], $userId, $logMsg);
            $up->execute([$log, $now, $id]);
            $done++;
        }
        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    private static function clearFlagForStock(
        int $stockId,
        int $userId,
        string $flagCol,
        string $logMsg
    ): array {
        if ($stockId < 1 || !in_array($flagCol, ['DT_REVISION', 'DT_MOVEMENT'], true)) {
            return ['done' => 0, 'skipped' => []];
        }
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, LOG AS log FROM base_good_sticker_ins
             WHERE ID_STOCK = ? AND `$flagCol` > 0"
        );
        $st->execute([$stockId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $done = 0;
        $up = Database::pdo()->prepare(
            "UPDATE base_good_sticker_ins SET LOG = ?, `$flagCol` = 0 WHERE ID = ?"
        );
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $log = self::appendLog((string)$row['log'], $userId, $logMsg);
            $up->execute([$log, $id]);
            $done++;
        }
        return ['done' => $done, 'skipped' => []];
    }
}
