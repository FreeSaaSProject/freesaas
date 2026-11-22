<?php
declare(strict_types=1);

/** Confirmation tasks (base_notify_tasks). STATUS: pending|done|cancelled|expired. */
final class NotifyTasksRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_notify_tasks');
    }

    /**
     * Idempotent on DEDUPE_KEY; returns the task row (existing or new), null when the table is missing.
     *
     * @param array<string,mixed> $d rule_id,event,entity,entity_id,client_id,stage_key,anchor_ts,due_ts,dedupe_key
     * @return array<string,mixed>|null
     */
    public static function create(array $d): ?array
    {
        if (!self::tableReady()) {
            return null;
        }
        $key = substr((string)($d['dedupe_key'] ?? ''), 0, 191);
        if ($key === '') {
            return null;
        }
        $now = time();
        Database::pdo()->prepare(
            'INSERT IGNORE INTO base_notify_tasks (RULE_ID, EVENT, ENTITY, ENTITY_ID, CLIENT_ID, STAGE_KEY, ANCHOR_TS,
             DUE_TS, STATUS, DEDUPE_KEY, DTCREATE, DTUPDATE) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            (int)($d['rule_id'] ?? 0),
            substr((string)($d['event'] ?? ''), 0, 64),
            substr((string)($d['entity'] ?? ''), 0, 32),
            (int)($d['entity_id'] ?? 0),
            (int)($d['client_id'] ?? 0),
            substr((string)($d['stage_key'] ?? ''), 0, 64),
            (int)($d['anchor_ts'] ?? 0),
            (int)($d['due_ts'] ?? 0),
            'pending',
            $key,
            $now,
            $now,
        ]);
        return self::findByDedupe($key);
    }

    /** @return array<string,mixed>|null */
    public static function findByDedupe(string $key): ?array
    {
        if (!self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_notify_tasks WHERE DEDUPE_KEY = ?');
        $st->execute([substr($key, 0, 191)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_notify_tasks WHERE ID = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> pending tasks whose DUE_TS has passed */
    public static function listDue(int $limit = 100): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            "SELECT * FROM base_notify_tasks WHERE STATUS = 'pending' AND DUE_TS <= ? ORDER BY DUE_TS ASC, ID ASC LIMIT "
            . max(1, min(500, $limit))
        );
        $st->execute([time()]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array{status?:string,client_id?:int,entity?:string,entity_id?:int,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $where = ['1=1'];
        $p = [];
        foreach (['status' => 'STATUS', 'entity' => 'ENTITY'] as $k => $col) {
            $v = trim((string)($opts[$k] ?? ''));
            if ($v !== '') {
                $where[] = $col . ' = ?';
                $p[] = $v;
            }
        }
        foreach (['client_id' => 'CLIENT_ID', 'entity_id' => 'ENTITY_ID'] as $k => $col) {
            if ((int)($opts[$k] ?? 0) > 0) {
                $where[] = $col . ' = ?';
                $p[] = (int)$opts[$k];
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_notify_tasks WHERE ' . $sqlWhere);
        $st->execute($p);
        $total = (int)$st->fetchColumn();
        $limit = max(1, min(500, (int)($opts['limit'] ?? 50)));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_notify_tasks WHERE ' . $sqlWhere . ' ORDER BY DUE_TS ASC, ID ASC LIMIT '
            . $limit . ' OFFSET ' . $offset
        );
        $st->execute($p);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /**
     * Atomic resolve: only a pending task can be resolved, exactly once.
     *
     * @return bool true when this call performed the transition
     */
    public static function resolve(int $id, string $outcome, int $by = 0, string $comment = ''): bool
    {
        if ($id < 1 || !self::tableReady()) {
            return false;
        }
        $now = time();
        $st = Database::pdo()->prepare(
            "UPDATE base_notify_tasks SET STATUS = 'done', OUTCOME = ?, RESOLVED_BY = ?, RESOLVED_AT = ?,
             RESOLVED_COMMENT = ?, DTUPDATE = ? WHERE ID = ? AND STATUS = 'pending'"
        );
        $st->execute([substr($outcome, 0, 32), $by, $now, $comment !== '' ? $comment : null, $now, $id]);
        return $st->rowCount() > 0;
    }

    /** Close pending tasks of an entity (order cancelled/completed, procedure deleted…). */
    public static function cancelByEntity(string $entity, int $entityId, string $reason = 'closed'): int
    {
        if ($entityId < 1 || !self::tableReady()) {
            return 0;
        }
        $now = time();
        $st = Database::pdo()->prepare(
            "UPDATE base_notify_tasks SET STATUS = 'cancelled', OUTCOME = ?, RESOLVED_AT = ?, DTUPDATE = ?
             WHERE ENTITY = ? AND ENTITY_ID = ? AND STATUS = 'pending'"
        );
        $st->execute([substr($reason, 0, 32), $now, $now, $entity, $entityId]);
        return $st->rowCount();
    }

    /** Move a pending task: bump attempts, set next due and last delivery. */
    public static function touch(int $id, int $attempts, int $dueTs, int $lastDeliveryId, string $outcomeState = ''): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare(
            "UPDATE base_notify_tasks SET ATTEMPTS = ?, DUE_TS = ?, LAST_DELIVERY_ID = ?, OUTCOME = ?, DTUPDATE = ?
             WHERE ID = ? AND STATUS = 'pending'"
        )->execute([$attempts, $dueTs, $lastDeliveryId, $outcomeState, time(), $id]);
    }

    public static function setStatus(int $id, string $status, string $outcome = ''): bool
    {
        if ($id < 1 || !in_array($status, ['cancelled', 'expired'], true) || !self::tableReady()) {
            return false;
        }
        $now = time();
        $st = Database::pdo()->prepare(
            "UPDATE base_notify_tasks SET STATUS = ?, OUTCOME = ?, RESOLVED_AT = ?, DTUPDATE = ?
             WHERE ID = ? AND STATUS = 'pending'"
        );
        $st->execute([$status, substr($outcome, 0, 32), $now, $now, $id]);
        return $st->rowCount() > 0;
    }

    public static function setMessage(int $id, string $platform, string $chatId, string $msgId): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare(
            'UPDATE base_notify_tasks SET PLATFORM = ?, CHAT_ID = ?, PLATFORM_MSG_ID = ? WHERE ID = ?'
        )->execute([substr($platform, 0, 16), substr($chatId, 0, 64), substr($msgId, 0, 128), $id]);
    }

    /** @param bool $askedOnly only tasks already sent to someone (waiting for an answer) */
    public static function countPending(bool $askedOnly = false): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        $sql = "SELECT COUNT(*) FROM base_notify_tasks WHERE STATUS = 'pending'";
        if ($askedOnly) {
            $sql .= ' AND ATTEMPTS > 0';
        }
        return (int)Database::pdo()->query($sql)->fetchColumn();
    }
}
