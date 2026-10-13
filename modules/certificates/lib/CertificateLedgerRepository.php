<?php
declare(strict_types=1);

/** Money movements for certificates — source of truth for balance. */
final class CertificateLedgerRepository
{
    public const DIR_CREDIT = 1;
    public const DIR_DEBIT = -1;

    public const REASON_ISSUE = 'issue';
    public const REASON_SPEND = 'spend';
    public const REASON_EXPIRE = 'expire';
    public const REASON_ADJUST = 'adjust';
    public const REASON_UNDO = 'undo';

    public static function tableReady(): bool
    {
        return Database::tableExists('base_certificate_ledger');
    }

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'l.ID',
            'dt_create' => 'l.DT_CREATE',
            'amount' => 'l.AMOUNT',
            'id_cert' => 'l.ID_CERT',
        ];
    }

    /**
     * @param array{
     *   q?:string,id_cert?:int,id_event?:int,id_order?:int,id_client?:int,
     *   reason?:string,limit?:int,offset?:int,order?:string
     * } $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(l.DESCR LIKE ? OR l.REASON LIKE ? OR CAST(l.ID AS CHAR) = ? OR CAST(l.ID_CERT AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
            $params[] = $q;
        }
        foreach (['id_cert' => 'ID_CERT', 'id_event' => 'ID_EVENT', 'id_order' => 'ID_ORDER', 'id_client' => 'ID_CLIENT'] as $opt => $col) {
            $v = (int)($opts[$opt] ?? 0);
            if ($v > 0) {
                $where[] = 'l.' . $col . ' = ?';
                $params[] = $v;
            }
        }
        $reason = trim((string)($opts['reason'] ?? ''));
        if ($reason !== '') {
            $where[] = 'l.REASON = ?';
            $params[] = $reason;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_certificate_ledger l WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'l.ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = 'SELECT l.ID AS id, l.ID_CERT AS id_cert, l.ID_OPERATOR AS id_operator,
                       l.ID_CLIENT AS id_client, l.ID_EVENT AS id_event, l.ID_ORDER AS id_order,
                       l.ID_REF AS id_ref, l.DT_CREATE AS dt_create, l.DIRECTION AS direction,
                       l.AMOUNT AS amount, l.REASON AS reason, l.DESCR AS descr
                FROM base_certificate_ledger l
                WHERE ' . $sqlWhere . ' ORDER BY ' . $order;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::normalizeRow($row);
        }
        return ['items' => $items, 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_CERT AS id_cert, ID_OPERATOR AS id_operator,
                    ID_CLIENT AS id_client, ID_EVENT AS id_event, ID_ORDER AS id_order,
                    ID_REF AS id_ref, DT_CREATE AS dt_create, DIRECTION AS direction,
                    AMOUNT AS amount, REASON AS reason, DESCR AS descr
             FROM base_certificate_ledger WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeRow($row) : null;
    }

    /** Lock ledger row for update (must be inside a transaction). */
    public static function findForUpdate(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_CERT AS id_cert, ID_OPERATOR AS id_operator,
                    ID_CLIENT AS id_client, ID_EVENT AS id_event, ID_ORDER AS id_order,
                    ID_REF AS id_ref, DT_CREATE AS dt_create, DIRECTION AS direction,
                    AMOUNT AS amount, REASON AS reason, DESCR AS descr
             FROM base_certificate_ledger WHERE ID = ? LIMIT 1 FOR UPDATE'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeRow($row) : null;
    }

    public static function balance(int $certId): float
    {
        return self::balanceRow($certId, false);
    }

    /**
     * Current-read balance under InnoDB REPEATABLE READ (after waiting on locks).
     * Must run inside a transaction; locks matching ledger rows.
     */
    public static function balanceForUpdate(int $certId): float
    {
        return self::balanceRow($certId, true);
    }

    private static function balanceRow(int $certId, bool $forUpdate): float
    {
        if ($certId < 1 || !self::tableReady()) {
            return 0.0;
        }
        $sql = 'SELECT COALESCE(SUM(DIRECTION * AMOUNT), 0) FROM base_certificate_ledger WHERE ID_CERT = ?';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute([$certId]);
        return round((float)$st->fetchColumn(), 2);
    }

    public static function hasIssue(int $certId): bool
    {
        if ($certId < 1 || !self::tableReady()) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT 1 FROM base_certificate_ledger WHERE ID_CERT = ? AND REASON = ? LIMIT 1'
        );
        $st->execute([$certId, self::REASON_ISSUE]);
        return (bool)$st->fetchColumn();
    }

    /** Active (not undone) spend on event. */
    public static function eventHasSpend(int $eventId, int $certId = 0): bool
    {
        return self::eventActiveSpend($eventId, $certId) !== null;
    }

    /** @return array<string,mixed>|null */
    public static function eventActiveSpend(int $eventId, int $certId = 0): ?array
    {
        return self::eventActiveSpendRow($eventId, $certId, false);
    }

    /** Current-read variant — use after acquiring locks under REPEATABLE READ. */
    public static function eventActiveSpendForUpdate(int $eventId, int $certId = 0): ?array
    {
        return self::eventActiveSpendRow($eventId, $certId, true);
    }

    /** @return array<string,mixed>|null */
    private static function eventActiveSpendRow(int $eventId, int $certId, bool $forUpdate): ?array
    {
        if ($eventId < 1 || !self::tableReady()) {
            return null;
        }
        $sql = 'SELECT s.ID AS id, s.ID_CERT AS id_cert, s.AMOUNT AS amount
                FROM base_certificate_ledger s
                WHERE s.ID_EVENT = ? AND s.REASON = ? AND s.DIRECTION < 0
                  AND NOT EXISTS (
                    SELECT 1 FROM base_certificate_ledger u
                    WHERE u.ID_REF = s.ID AND u.REASON = ?
                  )';
        $params = [$eventId, self::REASON_SPEND, self::REASON_UNDO];
        if ($certId > 0) {
            $sql .= ' AND s.ID_CERT = ?';
            $params[] = $certId;
        }
        $sql .= ' ORDER BY s.ID ASC LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'id_cert' => (int)$row['id_cert'],
            'amount' => (float)$row['amount'],
        ];
    }

    /** Active (not undone) spend on order — idempotency key. */
    public static function orderActiveSpend(int $orderId): ?array
    {
        return self::orderActiveSpendRow($orderId, false);
    }

    /** Current-read variant — use after acquiring locks under REPEATABLE READ. */
    public static function orderActiveSpendForUpdate(int $orderId): ?array
    {
        return self::orderActiveSpendRow($orderId, true);
    }

    /** @return array<string,mixed>|null */
    private static function orderActiveSpendRow(int $orderId, bool $forUpdate): ?array
    {
        if ($orderId < 1 || !self::tableReady()) {
            return null;
        }
        $sql = 'SELECT s.ID AS id, s.ID_CERT AS id_cert, s.AMOUNT AS amount
             FROM base_certificate_ledger s
             WHERE s.ID_ORDER = ? AND s.REASON = ? AND s.DIRECTION < 0
               AND NOT EXISTS (
                 SELECT 1 FROM base_certificate_ledger u
                 WHERE u.ID_REF = s.ID AND u.REASON = ?
               )
             ORDER BY s.ID ASC LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute([$orderId, self::REASON_SPEND, self::REASON_UNDO]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'id_cert' => (int)$row['id_cert'],
            'amount' => (float)$row['amount'],
        ];
    }

    public static function findUndoForSpend(int $spendId): ?array
    {
        return self::findUndoForSpendRow($spendId, false);
    }

    /** Current-read variant — use after acquiring locks under REPEATABLE READ. */
    public static function findUndoForSpendForUpdate(int $spendId): ?array
    {
        return self::findUndoForSpendRow($spendId, true);
    }

    /** @return array<string,mixed>|null */
    private static function findUndoForSpendRow(int $spendId, bool $forUpdate): ?array
    {
        if ($spendId < 1 || !self::tableReady()) {
            return null;
        }
        $sql = 'SELECT ID AS id, ID_CERT AS id_cert, AMOUNT AS amount
             FROM base_certificate_ledger
             WHERE ID_REF = ? AND REASON = ?
             ORDER BY ID ASC LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute([$spendId, self::REASON_UNDO]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? [
            'id' => (int)$row['id'],
            'id_cert' => (int)$row['id_cert'],
            'amount' => (float)$row['amount'],
        ] : null;
    }

    /**
     * @param array{
     *   id_cert:int,id_operator?:int,id_client?:int,id_event?:int,id_order?:int,
     *   id_ref?:int,direction:int,amount:float,reason:string,descr?:string,dt_create?:int
     * } $data
     */
    public static function insert(array $data): int
    {
        if (!self::tableReady()) {
            throw new RuntimeException(t('certificates.err.ledger_table'));
        }
        $amount = abs((float)($data['amount'] ?? 0));
        if ($amount <= 0) {
            throw new InvalidArgumentException(t('certificates.err.amount_required'));
        }
        $dir = (int)($data['direction'] ?? 0);
        $dir = $dir < 0 ? self::DIR_DEBIT : self::DIR_CREDIT;
        $st = Database::pdo()->prepare(
            'INSERT INTO base_certificate_ledger
             (ID_CERT, ID_OPERATOR, ID_CLIENT, ID_EVENT, ID_ORDER, ID_REF, DT_CREATE, DIRECTION, AMOUNT, REASON, DESCR)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            (int)$data['id_cert'],
            max(0, (int)($data['id_operator'] ?? 0)),
            max(0, (int)($data['id_client'] ?? 0)),
            max(0, (int)($data['id_event'] ?? 0)),
            max(0, (int)($data['id_order'] ?? 0)),
            max(0, (int)($data['id_ref'] ?? 0)),
            max(0, (int)($data['dt_create'] ?? time())) ?: time(),
            $dir,
            $amount,
            mb_substr((string)($data['reason'] ?? self::REASON_ADJUST), 0, 32),
            (string)($data['descr'] ?? ''),
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['id_cert'] = (int)($row['id_cert'] ?? 0);
        $row['id_operator'] = (int)($row['id_operator'] ?? 0);
        $row['id_client'] = (int)($row['id_client'] ?? 0);
        $row['id_event'] = (int)($row['id_event'] ?? 0);
        $row['id_order'] = (int)($row['id_order'] ?? 0);
        $row['id_ref'] = (int)($row['id_ref'] ?? 0);
        $row['dt_create'] = (int)($row['dt_create'] ?? 0);
        $row['direction'] = (int)($row['direction'] ?? 0);
        $row['amount'] = (float)($row['amount'] ?? 0);
        $row['reason'] = (string)($row['reason'] ?? '');
        $row['descr'] = (string)($row['descr'] ?? '');
        return $row;
    }
}
