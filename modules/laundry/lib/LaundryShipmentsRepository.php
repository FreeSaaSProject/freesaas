<?php
declare(strict_types=1);

final class LaundryShipmentsRepository
{
    /**
     * @param list<array{id_item_type:int,qty:int}> $lines
     */
    public static function createShipment(
        int $fromAcc,
        int $toAcc,
        array $lines,
        string $descr,
        float $weight,
        int $dt,
        int $userId,
        int $newsId = 0
    ): int {
        if ($fromAcc < 1 || $toAcc < 1) {
            throw new InvalidArgumentException(t('laundry.err.account_required'));
        }
        if ($fromAcc === $toAcc) {
            throw new InvalidArgumentException(t('laundry.err.accounts_same'));
        }
        $clean = [];
        foreach ($lines as $line) {
            $typeId = (int)($line['id_item_type'] ?? 0);
            $qty = (int)($line['qty'] ?? 0);
            if ($typeId < 1 || $qty < 1) {
                continue;
            }
            if (!LaundryItemTypesRepository::find($typeId)) {
                throw new InvalidArgumentException(t('laundry.err.type_required'));
            }
            $left = LaundryMovesRepository::balance($fromAcc, $typeId);
            if ($qty > $left) {
                throw new InvalidArgumentException(t('laundry.err.insufficient', ['left' => $left]));
            }
            $clean[] = ['id_item_type' => $typeId, 'qty' => $qty];
        }
        if ($clean === []) {
            throw new InvalidArgumentException(t('laundry.err.lines_required'));
        }
        $dt = $dt > 0 ? $dt : time();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $now = time();
            $pdo->prepare(
                'INSERT INTO base_laundry_shipments
                 (ID_ACCOUNT_FROM, ID_ACCOUNT_TO, ID_NEWS, ID_USER_CREATE, STATUS, DESCR, WEIGHT, DT, DT_CREATE)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $fromAcc, $toAcc, $newsId, $userId, 'open',
                mb_substr($descr, 0, 255), $weight, $dt, $now,
            ]);
            $shipId = (int)$pdo->lastInsertId();
            $insLine = $pdo->prepare(
                'INSERT INTO base_laundry_shipment_lines
                 (ID_SHIPMENT, ID_ITEM_TYPE, QTY_SENT, QTY_RETURNED) VALUES (?, ?, ?, 0)'
            );
            $baseDescr = $descr !== '' ? $descr : t('laundry.move.ship_descr', ['id' => $shipId]);
            foreach ($clean as $line) {
                $insLine->execute([$shipId, $line['id_item_type'], $line['qty']]);
                LaundryMovesRepository::insertTransferPair(
                    $pdo,
                    $fromAcc,
                    $toAcc,
                    $line['id_item_type'],
                    $line['qty'],
                    $baseDescr,
                    $weight,
                    $dt,
                    $userId,
                    $shipId,
                    0
                );
            }
            $pdo->commit();
            return $shipId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param list<array{id_shipment_line:int,qty:int}> $lines
     */
    public static function acceptReturn(
        array $lines,
        string $descr,
        float $weight,
        int $dt,
        int $userId,
        int $newsId = 0
    ): int {
        $clean = [];
        foreach ($lines as $line) {
            $lineId = (int)($line['id_shipment_line'] ?? 0);
            $qty = (int)($line['qty'] ?? 0);
            if ($lineId < 1 || $qty < 1) {
                continue;
            }
            $clean[] = ['id_shipment_line' => $lineId, 'qty' => $qty];
        }
        if ($clean === []) {
            throw new InvalidArgumentException(t('laundry.err.accept_empty'));
        }
        $dt = $dt > 0 ? $dt : time();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $now = time();
            // Lock lines and validate
            $byShip = [];
            $stLine = $pdo->prepare(
                'SELECT l.ID AS id, l.ID_SHIPMENT AS id_shipment, l.ID_ITEM_TYPE AS id_item_type,
                        l.QTY_SENT AS qty_sent, l.QTY_RETURNED AS qty_returned,
                        s.ID_ACCOUNT_FROM AS id_account_from, s.ID_ACCOUNT_TO AS id_account_to, s.STATUS AS status
                 FROM base_laundry_shipment_lines l
                 JOIN base_laundry_shipments s ON s.ID = l.ID_SHIPMENT
                 WHERE l.ID = ? FOR UPDATE'
            );
            $resolved = [];
            foreach ($clean as $c) {
                $stLine->execute([$c['id_shipment_line']]);
                $row = $stLine->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new InvalidArgumentException(t('laundry.err.not_found', ['id' => $c['id_shipment_line']]));
                }
                $open = (int)$row['qty_sent'] - (int)$row['qty_returned'];
                if ($c['qty'] > $open) {
                    throw new InvalidArgumentException(t('laundry.err.accept_over', [
                        'ship' => (int)$row['id_shipment'],
                        'type' => (int)$row['id_item_type'],
                        'max' => $open,
                    ]));
                }
                // Return: laundry account → terms (shipment was terms→laundry)
                $fromAcc = (int)$row['id_account_to'];
                $toAcc = (int)$row['id_account_from'];
                $left = LaundryMovesRepository::balance($fromAcc, (int)$row['id_item_type']);
                if ($c['qty'] > $left) {
                    throw new InvalidArgumentException(t('laundry.err.insufficient', ['left' => $left]));
                }
                $resolved[] = [
                    'line' => $row,
                    'qty' => $c['qty'],
                    'from' => $fromAcc,
                    'to' => $toAcc,
                ];
                $byShip[(int)$row['id_shipment']] = true;
            }

            $first = $resolved[0];
            $pdo->prepare(
                'INSERT INTO base_laundry_returns
                 (ID_ACCOUNT_FROM, ID_ACCOUNT_TO, ID_NEWS, ID_USER_CREATE, DESCR, WEIGHT, DT, DT_CREATE)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $first['from'], $first['to'], $newsId, $userId,
                mb_substr($descr, 0, 255), $weight, $dt, $now,
            ]);
            $retId = (int)$pdo->lastInsertId();
            $insRetLine = $pdo->prepare(
                'INSERT INTO base_laundry_return_lines
                 (ID_RETURN, ID_SHIPMENT_LINE, ID_ITEM_TYPE, QTY) VALUES (?, ?, ?, ?)'
            );
            $updLine = $pdo->prepare(
                'UPDATE base_laundry_shipment_lines SET QTY_RETURNED = QTY_RETURNED + ? WHERE ID = ?'
            );
            $baseDescr = $descr !== '' ? $descr : t('laundry.move.return_descr', ['id' => $retId]);
            foreach ($resolved as $r) {
                $typeId = (int)$r['line']['id_item_type'];
                $insRetLine->execute([$retId, (int)$r['line']['id'], $typeId, $r['qty']]);
                $updLine->execute([$r['qty'], (int)$r['line']['id']]);
                LaundryMovesRepository::insertTransferPair(
                    $pdo,
                    $r['from'],
                    $r['to'],
                    $typeId,
                    $r['qty'],
                    $baseDescr,
                    $weight,
                    $dt,
                    $userId,
                    (int)$r['line']['id_shipment'],
                    $retId
                );
            }
            foreach (array_keys($byShip) as $shipId) {
                self::refreshStatus($pdo, (int)$shipId);
            }
            $pdo->commit();
            return $retId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function refreshStatus(PDO $pdo, int $shipId): void
    {
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(QTY_SENT),0) AS sent, COALESCE(SUM(QTY_RETURNED),0) AS ret
             FROM base_laundry_shipment_lines WHERE ID_SHIPMENT = ?'
        );
        $st->execute([$shipId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['sent' => 0, 'ret' => 0];
        $sent = (int)$row['sent'];
        $ret = (int)$row['ret'];
        if ($ret <= 0) {
            $status = 'open';
        } elseif ($ret >= $sent) {
            $status = 'closed';
        } else {
            $status = 'partial';
        }
        $pdo->prepare('UPDATE base_laundry_shipments SET STATUS = ? WHERE ID = ?')->execute([$status, $shipId]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listOpenWithLines(): array
    {
        if (!Database::tableExists('base_laundry_shipments')) {
            return [];
        }
        $pdo = Database::pdo();
        $ships = $pdo->query(
            "SELECT ID AS id, ID_ACCOUNT_FROM AS id_account_from, ID_ACCOUNT_TO AS id_account_to,
                    STATUS AS status, DESCR AS descr, WEIGHT AS weight, DT AS dt
             FROM base_laundry_shipments
             WHERE STATUS IN ('open','partial')
             ORDER BY DT ASC, ID ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($ships === []) {
            return [];
        }
        $ids = array_map(static fn ($s) => (int)$s['id'], $ships);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT ID AS id, ID_SHIPMENT AS id_shipment, ID_ITEM_TYPE AS id_item_type,
                    QTY_SENT AS qty_sent, QTY_RETURNED AS qty_returned
             FROM base_laundry_shipment_lines
             WHERE ID_SHIPMENT IN ($ph)
             ORDER BY ID ASC"
        );
        $st->execute($ids);
        $byShip = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $line) {
            $open = (int)$line['qty_sent'] - (int)$line['qty_returned'];
            if ($open < 1) {
                continue;
            }
            $line['qty_open'] = $open;
            $byShip[(int)$line['id_shipment']][] = $line;
        }
        $out = [];
        foreach ($ships as $s) {
            $sid = (int)$s['id'];
            if (empty($byShip[$sid])) {
                continue;
            }
            $s['lines'] = $byShip[$sid];
            $out[] = $s;
        }
        return $out;
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_laundry_shipments')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $status = trim((string)($opts['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'STATUS = ?';
            $params[] = $status;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_laundry_shipments WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, ID_ACCOUNT_FROM AS id_account_from, ID_ACCOUNT_TO AS id_account_to,
                       STATUS AS status, DESCR AS descr, WEIGHT AS weight, DT AS dt, ID_USER_CREATE AS id_user
                FROM base_laundry_shipments WHERE $sqlWhere ORDER BY ID DESC";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($items) {
            $ids = array_map(static fn ($r) => (int)$r['id'], $items);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stL = $pdo->prepare(
                "SELECT ID_SHIPMENT AS sid, ID_ITEM_TYPE AS tid, QTY_SENT AS sent, QTY_RETURNED AS ret
                 FROM base_laundry_shipment_lines WHERE ID_SHIPMENT IN ($ph)"
            );
            $stL->execute($ids);
            $lines = [];
            foreach ($stL->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
                $lines[(int)$l['sid']][] = $l;
            }
            foreach ($items as &$it) {
                $it['lines'] = $lines[(int)$it['id']] ?? [];
            }
            unset($it);
        }
        return ['items' => $items, 'total' => $total];
    }
}
