<?php
declare(strict_types=1);

/** Client loyalty ledger on base_bonus_moves. */
final class BonusMovesRepository
{
    public const KIND_MANUAL = 'manual';
    public const KIND_EVENT_ACCRUE = 'event_accrue';
    public const KIND_EVENT_SPEND = 'event_spend';

    public const REF_EVENT = 'event';
    public const REF_ORDER = 'order';

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::KIND_MANUAL, self::KIND_EVENT_ACCRUE, self::KIND_EVENT_SPEND];
    }

    public static function tableReady(): bool
    {
        return Database::tableExists('base_bonus_moves');
    }

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'm.id',
            'client_id' => 'm.client_id',
            'created_at' => 'm.created_at',
            'expires_at' => 'm.expires_at',
            'amount' => 'm.amount',
            'direction' => 'm.direction',
            'kind' => 'm.kind',
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

    public static function kindLabel(string $kind): string
    {
        $key = 'bonuses.kind.' . $kind;
        $label = t($key);
        return $label !== $key ? $label : $kind;
    }

    /**
     * @param array{
     *   q?:string,
     *   client_id?:int,
     *   direction?:int|null,
     *   kind?:string|null,
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
            $where[] = '(m.note LIKE ? OR CAST(m.id AS CHAR) = ? OR CAST(m.client_id AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $q;
            $params[] = $q;
        }
        $clientId = (int)($opts['client_id'] ?? 0);
        if ($clientId > 0) {
            $where[] = 'm.client_id = ?';
            $params[] = $clientId;
        }
        if (array_key_exists('direction', $opts) && $opts['direction'] !== null && $opts['direction'] !== '') {
            $dir = (int)$opts['direction'];
            if ($dir > 0) {
                $where[] = 'm.direction > 0';
            } elseif ($dir < 0) {
                $where[] = 'm.direction < 0';
            } else {
                $where[] = 'm.direction = 0';
            }
        }
        $kind = trim((string)($opts['kind'] ?? ''));
        if ($kind !== '' && in_array($kind, self::kinds(), true)) {
            $where[] = 'm.kind = ?';
            $params[] = $kind;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_bonus_moves m WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'm.id DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT m.id, m.client_id, m.direction, m.amount, m.kind,
                       m.ref_type, m.ref_id, m.created_at, m.expires_at, m.note, m.dismissed
                FROM base_bonus_moves m
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
            'SELECT id, client_id, direction, amount, kind, ref_type, ref_id,
                    created_at, expires_at, note, dismissed
             FROM base_bonus_moves WHERE id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeRow($row) : null;
    }

    public static function balance(int $clientId): float
    {
        if ($clientId < 1 || !self::tableReady()) {
            return 0.0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(direction * amount), 0)
             FROM base_bonus_moves
             WHERE client_id = ? AND dismissed = 0'
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
            'SELECT 1 FROM base_bonus_moves
             WHERE ref_type = ? AND ref_id = ?
               AND kind IN (?, ?)
             LIMIT 1'
        );
        $st->execute([
            self::REF_EVENT,
            $eventId,
            self::KIND_EVENT_SPEND,
            self::KIND_EVENT_ACCRUE,
        ]);
        return (bool)$st->fetchColumn();
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $clientId = (int)($post['client_id'] ?? $post['id_user'] ?? 0);
        if ($clientId < 1) {
            throw new InvalidArgumentException(t('bonuses.err.client_required'));
        }
        $dir = (int)($post['direction'] ?? 0);
        if ($dir === 0) {
            throw new InvalidArgumentException(t('bonuses.err.direction'));
        }
        $dir = $dir > 0 ? 1 : -1;
        $amount = abs((float)($post['amount'] ?? $post['sum'] ?? 0));
        if ($amount <= 0) {
            throw new InvalidArgumentException(t('bonuses.err.sum_required'));
        }

        $kind = trim((string)($post['kind'] ?? self::KIND_MANUAL));
        if (!in_array($kind, self::kinds(), true)) {
            $kind = self::KIND_MANUAL;
        }

        $refType = trim((string)($post['ref_type'] ?? ''));
        if ($refType === '') {
            $refType = null;
        } elseif (!in_array($refType, [self::REF_EVENT, self::REF_ORDER], true)) {
            $refType = null;
        }
        $refId = max(0, (int)($post['ref_id'] ?? 0)) ?: null;
        if ($refType === null) {
            $refId = null;
        }

        $createdAt = self::parseLocalTs((string)($post['created_at'] ?? $post['date_in'] ?? ''));
        if ($createdAt < 1) {
            $createdAt = time();
        }
        $expiresRaw = trim((string)($post['expires_at'] ?? $post['date_out'] ?? ''));
        $expiresAt = $expiresRaw === '' ? null : self::parseLocalTs($expiresRaw);
        if ($expiresAt !== null && $expiresAt < 1) {
            $expiresAt = null;
        }

        return [
            'client_id' => $clientId,
            'direction' => $dir,
            'amount' => $amount,
            'kind' => $kind,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
            'note' => mb_substr(trim((string)($post['note'] ?? $post['description'] ?? '')), 0, 500),
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
            'INSERT INTO base_bonus_moves
             (client_id, direction, amount, kind, ref_type, ref_id, created_at, expires_at, note, dismissed)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            (int)$data['client_id'],
            (int)$data['direction'],
            (float)$data['amount'],
            (string)$data['kind'],
            $data['ref_type'] ?? null,
            $data['ref_id'] ?? null,
            (int)$data['created_at'],
            $data['expires_at'] ?? null,
            (string)($data['note'] ?? ''),
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
            'UPDATE base_bonus_moves
             SET client_id = ?, direction = ?, amount = ?, kind = ?, ref_type = ?, ref_id = ?,
                 created_at = ?, expires_at = ?, note = ?, dismissed = ?
             WHERE id = ?'
        );
        $st->execute([
            (int)$data['client_id'],
            (int)$data['direction'],
            (float)$data['amount'],
            (string)$data['kind'],
            $data['ref_type'] ?? null,
            $data['ref_id'] ?? null,
            (int)$data['created_at'],
            $data['expires_at'] ?? null,
            (string)($data['note'] ?? ''),
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
        $st = Database::pdo()->prepare("DELETE FROM base_bonus_moves WHERE id IN ($ph)");
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
        $row['client_id'] = (int)($row['client_id'] ?? 0);
        $row['direction'] = (int)($row['direction'] ?? 0);
        $row['amount'] = (float)($row['amount'] ?? 0);
        $row['kind'] = (string)($row['kind'] ?? self::KIND_MANUAL);
        $row['ref_type'] = isset($row['ref_type']) && $row['ref_type'] !== ''
            ? (string)$row['ref_type']
            : null;
        $row['ref_id'] = (int)($row['ref_id'] ?? 0);
        $row['created_at'] = (int)($row['created_at'] ?? 0);
        $expires = $row['expires_at'] ?? null;
        $row['expires_at'] = $expires === null || $expires === '' ? null : (int)$expires;
        $row['note'] = (string)($row['note'] ?? '');
        $row['dismissed'] = (int)($row['dismissed'] ?? 0);
        return $row;
    }
}
