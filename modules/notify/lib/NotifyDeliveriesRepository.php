<?php
declare(strict_types=1);

/** Delivery journal (base_notify_deliveries). STATUS: queued|sent|error|skipped. */
final class NotifyDeliveriesRepository
{
    public const STATUSES = ['queued', 'sent', 'error', 'skipped'];

    public static function tableReady(): bool
    {
        return Database::tableExists('base_notify_deliveries');
    }

    /**
     * @param array<string,mixed> $d keys mirror columns in lower case
     */
    public static function insert(array $d): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        $meta = $d['meta'] ?? null;
        $metaJson = is_array($meta) && $meta !== []
            ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)
            : null;
        Database::pdo()->prepare(
            'INSERT INTO base_notify_deliveries (DTCREATE, RULE_ID, EVENT, ENTITY, ENTITY_ID, CLIENT_ID, RCPT_KIND,
             RCPT_ID, CHANNEL, ADDRESS, SUBJECT, TEXT, STATUS, QUEUE_ID, QUEUE_KIND, PLATFORM_MSG_ID, ERROR,
             DEDUPE_KEY, META_JSON, TASK_ID)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            time(),
            (int)($d['rule_id'] ?? 0),
            substr((string)($d['event'] ?? ''), 0, 64),
            substr((string)($d['entity'] ?? ''), 0, 32),
            (int)($d['entity_id'] ?? 0),
            (int)($d['client_id'] ?? 0),
            substr((string)($d['rcpt_kind'] ?? ''), 0, 16),
            substr((string)($d['rcpt_id'] ?? ''), 0, 64),
            substr((string)($d['channel'] ?? ''), 0, 16),
            substr((string)($d['address'] ?? ''), 0, 255),
            substr((string)($d['subject'] ?? ''), 0, 255),
            (string)($d['text'] ?? ''),
            in_array((string)($d['status'] ?? ''), self::STATUSES, true) ? (string)$d['status'] : 'queued',
            (int)($d['queue_id'] ?? 0),
            substr((string)($d['queue_kind'] ?? ''), 0, 16),
            substr((string)($d['platform_msg_id'] ?? ''), 0, 128),
            substr((string)($d['error'] ?? ''), 0, 512),
            substr((string)($d['dedupe_key'] ?? ''), 0, 191),
            $metaJson !== false ? $metaJson : null,
            (int)($d['task_id'] ?? 0),
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** True when a queued/sent delivery already exists for this key (idempotent re-emits). */
    public static function hasLive(string $dedupeKey): bool
    {
        if ($dedupeKey === '' || !self::tableReady()) {
            return false;
        }
        $st = Database::pdo()->prepare(
            "SELECT 1 FROM base_notify_deliveries WHERE DEDUPE_KEY = ? AND STATUS IN ('queued','sent') LIMIT 1"
        );
        $st->execute([substr($dedupeKey, 0, 191)]);
        return (bool)$st->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_notify_deliveries WHERE ID = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function mark(int $id, string $status, string $error = '', string $platformMsgId = ''): void
    {
        if ($id < 1 || !in_array($status, self::STATUSES, true) || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare(
            'UPDATE base_notify_deliveries SET STATUS = ?, ERROR = ?,
             PLATFORM_MSG_ID = IF(? = \'\', PLATFORM_MSG_ID, ?) WHERE ID = ?'
        )->execute([$status, substr($error, 0, 512), $platformMsgId, substr($platformMsgId, 0, 128), $id]);
    }

    /** @return list<array<string,mixed>> queued rows still waiting for the transport to report */
    public static function listQueued(int $limit = 100): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $st = Database::pdo()->query(
            "SELECT * FROM base_notify_deliveries WHERE STATUS = 'queued' AND QUEUE_ID > 0
             ORDER BY ID ASC LIMIT " . $limit
        );
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array{client_id?:int,scope?:string,rule_id?:int,event?:string,status?:string,entity?:string,
     *   entity_id?:int,rcpt_kind?:string,rcpt_id?:string,q?:string,limit?:int,offset?:int,order?:string} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $where = ['1=1'];
        $p = [];
        $clientId = (int)($opts['client_id'] ?? 0);
        if ($clientId > 0) {
            $where[] = 'CLIENT_ID = ?';
            $p[] = $clientId;
            $scope = (string)($opts['scope'] ?? '');
            if ($scope === 'to_client') {
                $where[] = "RCPT_KIND = 'client'";
            } elseif ($scope === 'about_client') {
                $where[] = "RCPT_KIND <> 'client'";
            }
        }
        foreach (['rule_id' => 'RULE_ID', 'entity_id' => 'ENTITY_ID'] as $k => $col) {
            if ((int)($opts[$k] ?? 0) > 0) {
                $where[] = $col . ' = ?';
                $p[] = (int)$opts[$k];
            }
        }
        foreach (['event' => 'EVENT', 'status' => 'STATUS', 'entity' => 'ENTITY', 'rcpt_kind' => 'RCPT_KIND', 'rcpt_id' => 'RCPT_ID'] as $k => $col) {
            $v = trim((string)($opts[$k] ?? ''));
            if ($v !== '') {
                $where[] = $col . ' = ?';
                $p[] = $v;
            }
        }
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(ADDRESS LIKE ? OR SUBJECT LIKE ? OR TEXT LIKE ?)';
            $like = '%' . $q . '%';
            array_push($p, $like, $like, $like);
        }
        $sqlWhere = implode(' AND ', $where);
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_notify_deliveries WHERE ' . $sqlWhere);
        $st->execute($p);
        $total = (int)$st->fetchColumn();

        $limit = (int)($opts['limit'] ?? 50);
        $limit = $limit > 0 ? min(3000, $limit) : 0;
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $order = trim((string)($opts['order'] ?? '')) ?: 'ID DESC';
        $sql = 'SELECT * FROM base_notify_deliveries WHERE ' . $sqlWhere . ' ORDER BY ' . $order;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($p);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return ['id' => 'ID', 'dtcreate' => 'DTCREATE', 'status' => 'STATUS', 'event' => 'EVENT', 'channel' => 'CHANNEL'];
    }

    public static function purgeOlderThan(int $days): int
    {
        if ($days < 1 || !self::tableReady()) {
            return 0;
        }
        $st = Database::pdo()->prepare('DELETE FROM base_notify_deliveries WHERE DTCREATE < ?');
        $st->execute([time() - $days * 86400]);
        return $st->rowCount();
    }

    /** @return array<string,int> counts per STATUS for the last 24h */
    public static function statusCounts(): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        if (!self::tableReady()) {
            return $out;
        }
        $st = Database::pdo()->prepare('SELECT STATUS, COUNT(*) AS n FROM base_notify_deliveries WHERE DTCREATE >= ? GROUP BY STATUS');
        $st->execute([time() - 86400]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(string)$r['STATUS']] = (int)$r['n'];
        }
        return $out;
    }
}
