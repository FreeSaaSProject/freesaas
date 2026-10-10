<?php
declare(strict_types=1);

final class GcalSyncRepository
{
    public static function getToken(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_gcal_tokens WHERE ID_USER = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array{access_token:string,refresh_token?:string,expires_at:int} $tok
     */
    public static function upsertToken(int $userId, array $tok): void
    {
        $now = time();
        $exist = self::getToken($userId);
        if ($exist) {
            $refresh = trim((string)($tok['refresh_token'] ?? '')) !== ''
                ? (string)$tok['refresh_token']
                : (string)$exist['REFRESH_TOKEN'];
            $st = Database::pdo()->prepare(
                'UPDATE base_gcal_tokens
                 SET ACCESS_TOKEN = ?, REFRESH_TOKEN = ?, EXPIRES_AT = ?, DT_UPDATE = ?
                 WHERE ID_USER = ?'
            );
            $st->execute([
                (string)$tok['access_token'],
                $refresh,
                (int)$tok['expires_at'],
                $now,
                $userId,
            ]);
            return;
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_gcal_tokens
             (ID_USER, ACCESS_TOKEN, REFRESH_TOKEN, EXPIRES_AT, DT_CREATE, DT_UPDATE)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $userId,
            (string)$tok['access_token'],
            (string)($tok['refresh_token'] ?? ''),
            (int)$tok['expires_at'],
            $now,
            $now,
        ]);
    }

    public static function setCalendars(int $userId, string $activeId, string $doneId): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_gcal_tokens
             SET ACTIVE_CALENDAR_ID = ?, DONE_CALENDAR_ID = ?, DT_UPDATE = ?
             WHERE ID_USER = ?'
        );
        $st->execute([$activeId, $doneId, time(), $userId]);
    }

    public static function getLink(int $taskId, int $userId): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_gcal_task_links WHERE ID_TASK = ? AND ID_USER = ? LIMIT 1'
        );
        $st->execute([$taskId, $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function linksForTask(int $taskId): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM base_gcal_task_links WHERE ID_TASK = ?');
        $st->execute([$taskId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $fields
     */
    public static function upsertLink(int $taskId, int $userId, array $fields): void
    {
        $now = time();
        $exist = self::getLink($taskId, $userId);
        if ($exist) {
            $st = Database::pdo()->prepare(
                'UPDATE base_gcal_task_links SET
                 ACTIVE_CALENDAR_ID = ?, DONE_CALENDAR_ID = ?, EVENT_ID = ?, EVENT_ETAG = ?,
                 SLOT_INDEX = ?, SYNC_HASH = ?, LAST_SYNC_DIR = ?, DT_UPDATE = ?
                 WHERE ID_TASK = ? AND ID_USER = ?'
            );
            $st->execute([
                (string)($fields['active_calendar_id'] ?? $exist['ACTIVE_CALENDAR_ID']),
                (string)($fields['done_calendar_id'] ?? $exist['DONE_CALENDAR_ID']),
                (string)($fields['event_id'] ?? $exist['EVENT_ID']),
                (string)($fields['event_etag'] ?? $exist['EVENT_ETAG']),
                (int)($fields['slot_index'] ?? $exist['SLOT_INDEX']),
                (string)($fields['sync_hash'] ?? $exist['SYNC_HASH']),
                (string)($fields['last_sync_dir'] ?? $exist['LAST_SYNC_DIR']),
                $now,
                $taskId,
                $userId,
            ]);
            return;
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_gcal_task_links
             (ID_TASK, ID_USER, ACTIVE_CALENDAR_ID, DONE_CALENDAR_ID, EVENT_ID, EVENT_ETAG,
              SLOT_INDEX, SYNC_HASH, LAST_SYNC_DIR, DT_UPDATE)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $taskId,
            $userId,
            (string)($fields['active_calendar_id'] ?? ''),
            (string)($fields['done_calendar_id'] ?? ''),
            (string)($fields['event_id'] ?? ''),
            (string)($fields['event_etag'] ?? ''),
            (int)($fields['slot_index'] ?? 0),
            (string)($fields['sync_hash'] ?? ''),
            (string)($fields['last_sync_dir'] ?? 'out'),
            $now,
        ]);
    }

    public static function deleteLink(int $taskId, int $userId): void
    {
        $st = Database::pdo()->prepare(
            'DELETE FROM base_gcal_task_links WHERE ID_TASK = ? AND ID_USER = ?'
        );
        $st->execute([$taskId, $userId]);
    }

    public static function enqueue(int $taskId, string $reason = 'update', int $userId = 0, bool $force = false): void
    {
        if ($taskId <= 0 || !GcalConfig::enabled()) {
            return;
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_gcal_sync_queue
             (ID_TASK, ID_USER, REASON, FORCE_SYNC, STATUS, ATTEMPTS, LAST_ERROR, DT_CREATE, DT_UPDATE)
             VALUES (?, ?, ?, ?, 0, 0, \'\', ?, ?)'
        );
        $now = time();
        $st->execute([$taskId, $userId, $reason, $force ? 1 : 0, $now, $now]);
    }

    public static function pendingCount(): int
    {
        return (int)Database::pdo()->query(
            'SELECT COUNT(*) FROM base_gcal_sync_queue WHERE STATUS = 0'
        )->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function claimBatch(int $limit = 20): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT * FROM base_gcal_sync_queue WHERE STATUS = 0 ORDER BY ID ASC LIMIT ' . max(1, $limit)
        );
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static function ($r) {
            return (int)$r['ID'];
        }, $rows);
        $in = implode(',', $ids);
        $pdo->exec(
            'UPDATE base_gcal_sync_queue SET STATUS = 1, DT_UPDATE = ' . time()
            . ' WHERE ID IN (' . $in . ')'
        );
        return $rows;
    }

    public static function markDone(int $id, string $error = ''): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_gcal_sync_queue SET STATUS = 2, LAST_ERROR = ?, DT_UPDATE = ? WHERE ID = ?'
        );
        $st->execute([$error, time(), $id]);
    }

    public static function markFail(int $id, string $error): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_gcal_sync_queue
             SET STATUS = 3, ATTEMPTS = ATTEMPTS + 1, LAST_ERROR = ?, DT_UPDATE = ?
             WHERE ID = ?'
        );
        $st->execute([$error, time(), $id]);
    }

    /**
     * @return list<array{ID:int,NAME:string,connected:bool,active_cal:string,done_cal:string}>
     */
    public static function staffOverview(): array
    {
        $out = [];
        foreach (UsersRepository::listEnabledStaff() as $u) {
            $uid = (int)$u['id'];
            $t = self::getToken($uid);
            $label = trim((string)$u['name']) !== '' ? (string)$u['name'] : (string)$u['login'];
            $out[] = [
                'ID' => $uid,
                'NAME' => $label,
                'connected' => $t !== null && trim((string)$t['REFRESH_TOKEN']) !== '',
                'active_cal' => $t ? (string)$t['ACTIVE_CALENDAR_ID'] : '',
                'done_cal' => $t ? (string)$t['DONE_CALENDAR_ID'] : '',
            ];
        }
        return $out;
    }
}
