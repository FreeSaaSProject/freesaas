<?php
declare(strict_types=1);

/** Client loyalty ledger on base_bonuspay (oper parity). */
final class BonusPayRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_bonuspay');
    }

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'b.ID',
            'id_user' => 'b.ID_USER',
            'date_in' => 'b.DATE_IN',
            'date_out' => 'b.DATE_OUT',
            'sum' => 'b.SUM',
            'direction' => 'b.DIRECTION',
        ];
    }

    public static function directionLabel(int $dir): string
    {
        if ($dir > 0) {
            return t('bonuses.dir.credit');
        }
        if ($dir < 0) {
            return t('bonuses.dir.debit');
        }
        return t('bonuses.dir.none');
    }

    /**
     * @param array{
     *   q?:string,
     *   id_user?:int,
     *   direction?:int|null,
     *   limit?:int,
     *   offset?:int,
     *   order?:string
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
            $where[] = '(b.DESCRIPTION LIKE ? OR CAST(b.ID AS CHAR) = ? OR CAST(b.ID_USER AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $q;
            $params[] = $q;
        }
        $idUser = (int)($opts['id_user'] ?? 0);
        if ($idUser > 0) {
            $where[] = 'b.ID_USER = ?';
            $params[] = $idUser;
        }
        if (array_key_exists('direction', $opts) && $opts['direction'] !== null && $opts['direction'] !== '') {
            $dir = (int)$opts['direction'];
            if ($dir > 0) {
                $where[] = 'b.DIRECTION > 0';
            } elseif ($dir < 0) {
                $where[] = 'b.DIRECTION < 0';
            } else {
                $where[] = 'b.DIRECTION = 0';
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_bonuspay b WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'b.ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT b.ID AS id, b.ID_USER AS id_user, b.ID_EVENT_BONUS AS id_event_bonus,
                       b.ID_EVENT_DISCOUNT AS id_event_discount, b.ID_ORDER AS id_order,
                       b.DATE_IN AS date_in, b.DATE_OUT AS date_out, b.DESCRIPTION AS description,
                       b.DIRECTION AS direction, b.SUM AS sum, b.DISMISSED AS dismissed
                FROM base_bonuspay b
                WHERE $sqlWhere
                ORDER BY $order";
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
            'SELECT ID AS id, ID_USER AS id_user, ID_EVENT_BONUS AS id_event_bonus,
                    ID_EVENT_DISCOUNT AS id_event_discount, ID_ORDER AS id_order,
                    DATE_IN AS date_in, DATE_OUT AS date_out, DESCRIPTION AS description,
                    DIRECTION AS direction, SUM AS sum, DISMISSED AS dismissed
             FROM base_bonuspay WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeRow($row) : null;
    }

    /** Active balance: SUM(DIRECTION * SUM) ignoring dismissed optional — oper uses all rows. */
    public static function balance(int $clientId): float
    {
        if ($clientId < 1 || !self::tableReady()) {
            return 0.0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(DIRECTION * SUM), 0) FROM base_bonuspay WHERE ID_USER = ?'
        );
        $st->execute([$clientId]);
        return round((float)$st->fetchColumn(), 2);
    }

    public static function eventHasSettlement(int $eventId): bool
    {
        if ($eventId < 1 || !self::tableReady()) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT 1 FROM base_bonuspay
             WHERE ID_EVENT_BONUS = ? OR ID_EVENT_DISCOUNT = ?
             LIMIT 1'
        );
        $st->execute([$eventId, $eventId]);
        return (bool)$st->fetchColumn();
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $idUser = (int)($post['id_user'] ?? 0);
        if ($idUser < 1) {
            throw new InvalidArgumentException(t('bonuses.err.client_required'));
        }
        $dir = (int)($post['direction'] ?? 0);
        if ($dir === 0) {
            throw new InvalidArgumentException(t('bonuses.err.direction'));
        }
        $dir = $dir > 0 ? 1 : -1;
        $sum = abs((float)($post['sum'] ?? 0));
        if ($sum <= 0) {
            throw new InvalidArgumentException(t('bonuses.err.sum_required'));
        }

        $dateIn = self::parseLocalTs((string)($post['date_in'] ?? ''));
        if ($dateIn < 1) {
            $dateIn = time();
        }
        $dateOut = self::parseLocalTs((string)($post['date_out'] ?? ''));
        if ($dateOut < 1) {
            $dateOut = $dateIn + (int)(60 * 60 * 24 * 30 * 6);
        }

        return [
            'id_user' => $idUser,
            'id_event_bonus' => max(0, (int)($post['id_event_bonus'] ?? 0)) ?: null,
            'id_event_discount' => max(0, (int)($post['id_event_discount'] ?? 0)) ?: null,
            'id_order' => max(0, (int)($post['id_order'] ?? 0)) ?: null,
            'date_in' => $dateIn,
            'date_out' => $dateOut,
            'description' => mb_substr(trim((string)($post['description'] ?? '')), 0, 2000),
            'direction' => $dir,
            'sum' => $sum,
            'dismissed' => !empty($post['dismissed']) ? 1 : 0,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        if (!self::tableReady()) {
            throw new RuntimeException(t('bonuses.err.table'));
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_bonuspay
             (ID_USER, ID_EVENT_BONUS, ID_EVENT_DISCOUNT, ID_ORDER, DATE_IN, DATE_OUT,
              DESCRIPTION, DIRECTION, SUM, DISMISSED)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            (int)$data['id_user'],
            $data['id_event_bonus'] ?? null,
            $data['id_event_discount'] ?? null,
            $data['id_order'] ?? null,
            (int)$data['date_in'],
            (int)$data['date_out'],
            (string)($data['description'] ?? ''),
            (int)$data['direction'],
            (float)$data['sum'],
            (int)($data['dismissed'] ?? 0),
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_bonuspay
             SET ID_USER = ?, ID_EVENT_BONUS = ?, ID_EVENT_DISCOUNT = ?, ID_ORDER = ?,
                 DATE_IN = ?, DATE_OUT = ?, DESCRIPTION = ?, DIRECTION = ?, SUM = ?, DISMISSED = ?
             WHERE ID = ?'
        );
        $st->execute([
            (int)$data['id_user'],
            $data['id_event_bonus'] ?? null,
            $data['id_event_discount'] ?? null,
            $data['id_order'] ?? null,
            (int)$data['date_in'],
            (int)$data['date_out'],
            (string)($data['description'] ?? ''),
            (int)$data['direction'],
            (float)$data['sum'],
            (int)($data['dismissed'] ?? 0),
            $id,
        ]);
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        if (!self::tableReady() || $ids === []) {
            return 0;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_bonuspay WHERE ID IN ($ph)");
        $st->execute($ids);
        return $st->rowCount();
    }

    public static function tsToLocal(int $ts): string
    {
        if ($ts <= 0) {
            return '';
        }
        return date('Y-m-d\TH:i', $ts);
    }

    public static function parseLocalTs(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        $ts = strtotime($raw);
        return $ts !== false ? (int)$ts : 0;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['id_user'] = (int)($row['id_user'] ?? 0);
        $row['id_event_bonus'] = (int)($row['id_event_bonus'] ?? 0);
        $row['id_event_discount'] = (int)($row['id_event_discount'] ?? 0);
        $row['id_order'] = (int)($row['id_order'] ?? 0);
        $row['date_in'] = (int)($row['date_in'] ?? 0);
        $row['date_out'] = (int)($row['date_out'] ?? 0);
        $row['direction'] = (int)($row['direction'] ?? 0);
        $row['sum'] = (float)($row['sum'] ?? 0);
        $row['dismissed'] = (int)($row['dismissed'] ?? 0);
        return $row;
    }
}
