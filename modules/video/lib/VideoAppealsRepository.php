<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Appeals against absence alerts: filed by the attributed staff member, decided by users
 * holding permission `video.appeals.decide`. Accepting cancels the alert (STATE=1) and emits
 * `video.appeal.decided` so other modules (e.g. audio) can undo the consequences.
 */
final class VideoAppealsRepository
{
    public const PENDING = 0;
    public const ACCEPTED = 1;
    public const REJECTED = 2;

    public const PERM_DECIDE = 'video.appeals.decide';

    public static function tableReady(): bool
    {
        return Database::tableExists('base_video_appeals');
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(self::selectSql() . ' WHERE ap.ID = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public static function findByAlert(int $alertId): ?array
    {
        if ($alertId < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(self::selectSql() . ' WHERE ap.ALERT_ID = ?');
        $st->execute([$alertId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array{status?:int,staff_id?:int} $filter status < 0 or absent = any
     * @return list<array<string,mixed>>
     */
    public static function list(array $filter = [], int $limit = 100): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $where = ['1=1'];
        $params = [];
        if (isset($filter['status']) && (int)$filter['status'] >= 0) {
            $where[] = 'ap.STATUS = ?';
            $params[] = (int)$filter['status'];
        }
        if (!empty($filter['staff_id'])) {
            $where[] = 'ap.STAFF_ID = ?';
            $params[] = (int)$filter['staff_id'];
        }
        $st = Database::pdo()->prepare(
            self::selectSql() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY ap.ID DESC LIMIT ' . max(1, min(500, $limit))
        );
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function pendingCount(): int
    {
        if (!self::tableReady()) {
            return 0;
        }

        return (int)Database::pdo()->query('SELECT COUNT(*) FROM base_video_appeals WHERE STATUS = 0')->fetchColumn();
    }

    /**
     * File an appeal. Only the staff member the alert is attributed to may file it, once per alert.
     *
     * @return array{ok:bool,id:int,error:string}
     */
    public static function create(int $alertId, int $staffId, string $reason): array
    {
        $alert = VideoAlertsRepository::find($alertId);
        if ($alert === null || !self::tableReady()) {
            return ['ok' => false, 'id' => 0, 'error' => 'alert_not_found'];
        }
        if ((int)($alert['STAFF_ID'] ?? 0) < 1 || (int)$alert['STAFF_ID'] !== $staffId) {
            return ['ok' => false, 'id' => 0, 'error' => 'not_your_alert'];
        }
        if ((int)($alert['STATE'] ?? 0) !== 0) {
            return ['ok' => false, 'id' => 0, 'error' => 'alert_cancelled'];
        }
        if (self::findByAlert($alertId) !== null) {
            return ['ok' => false, 'id' => 0, 'error' => 'already_appealed'];
        }
        $reason = trim(mb_substr($reason, 0, 1000));
        Database::pdo()->prepare(
            'INSERT INTO base_video_appeals (ALERT_ID, STAFF_ID, DT_CREATE, REASON, STATUS) VALUES (?, ?, ?, ?, 0)'
        )->execute([$alertId, $staffId, time(), $reason]);
        $id = (int)Database::pdo()->lastInsertId();
        EventBus::emit('video.appeal.created', [
            'appeal_id' => $id,
            'alert_id' => $alertId,
            'staff_id' => $staffId,
            'reason' => $reason,
        ]);

        return ['ok' => true, 'id' => $id, 'error' => ''];
    }

    /**
     * @param int $status ACCEPTED|REJECTED
     * @return array{ok:bool,error:string}
     */
    public static function decide(int $id, int $status, int $deciderId, string $comment = ''): array
    {
        if (!in_array($status, [self::ACCEPTED, self::REJECTED], true)) {
            return ['ok' => false, 'error' => 'bad_status'];
        }
        $ap = self::find($id);
        if ($ap === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $comment = trim(mb_substr($comment, 0, 1000));
        // Atomic: only a still-pending row can be decided (double click / two deciders).
        $st = Database::pdo()->prepare(
            'UPDATE base_video_appeals SET STATUS = ?, DECIDER_ID = ?, DT_DECISION = ?, COMMENT = ? WHERE ID = ? AND STATUS = 0'
        );
        $st->execute([$status, $deciderId, time(), $comment, $id]);
        if ($st->rowCount() < 1) {
            return ['ok' => false, 'error' => 'already_decided'];
        }
        $alertId = (int)$ap['ALERT_ID'];
        if ($status === self::ACCEPTED) {
            VideoAlertsRepository::setState($alertId, 1);
        }
        $alert = VideoAlertsRepository::find($alertId);
        EventBus::emit('video.appeal.decided', [
            'appeal_id' => $id,
            'alert_id' => $alertId,
            'staff_id' => (int)$ap['STAFF_ID'],
            'status' => $status,
            'accepted' => $status === self::ACCEPTED,
            'decider_id' => $deciderId,
            'comment' => $comment,
            'alert_dt' => (int)($alert['DT'] ?? 0),
            'camera_id' => (int)($alert['CAMERA_ID'] ?? 0),
            'alert_meta' => json_decode((string)($alert['META_JSON'] ?? ''), true) ?: [],
        ]);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Enabled staff holding video.appeals.decide (personal blob) or a SUPERADMIN_ACCESS role.
     *
     * @return list<array{id:int,name:string}>
     */
    public static function deciders(): array
    {
        if (!Database::tableExists('base_users')) {
            return [];
        }
        $cols = 'id, login, name, status';
        if (Database::columnExists('base_users', 'ID_STATUS')) {
            $cols .= ', ID_STATUS';
        }
        $rows = Database::pdo()->query("SELECT {$cols} FROM base_users WHERE enabled = '1' LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $perms = UsersRepository::decodePermissions($r['status'] ?? null);
            $super = UsersRepository::statusIsSuperadmin((int)($r['ID_STATUS'] ?? 0));
            if (!$super && empty($perms[self::PERM_DECIDE])) {
                continue;
            }
            $name = trim((string)($r['name'] ?? ''));
            $out[] = ['id' => (int)$r['id'], 'name' => $name !== '' ? $name : (string)$r['login']];
        }

        return $out;
    }

    private static function selectSql(): string
    {
        return "SELECT ap.*, a.CAMERA_ID, a.DT AS ALERT_DT, a.KIND, a.MESSAGE, a.FRAME_PATHS_JSON, a.STATE AS ALERT_STATE,
                       COALESCE(NULLIF(us.name, ''), us.login) AS STAFF_NAME,
                       COALESCE(NULLIF(ud.name, ''), ud.login) AS DECIDER_NAME,
                       c.NAME AS CAMERA_NAME
                FROM base_video_appeals ap
                LEFT JOIN base_video_alerts a ON a.ID = ap.ALERT_ID
                LEFT JOIN base_video_cameras c ON c.ID = a.CAMERA_ID
                LEFT JOIN base_users us ON us.id = ap.STAFF_ID
                LEFT JOIN base_users ud ON ud.id = ap.DECIDER_ID";
    }
}
