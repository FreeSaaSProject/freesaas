<?php
declare(strict_types=1);

/**
 * Permanent staff NFC UID ↔ base_users.id (terminals identity, not locker registry).
 */
final class StaffBraceletsRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_staff_bracelets');
    }

    /** @return array{user_id:int,uid:string,updated_at:int}|null */
    public static function findByUserId(int $userId): ?array
    {
        if ($userId < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT user_id, uid, updated_at FROM base_staff_bracelets WHERE user_id = ? LIMIT 1'
        );
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'user_id' => (int)$row['user_id'],
            'uid' => (string)$row['uid'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }

    /** @return array{user_id:int,uid:string,updated_at:int}|null */
    public static function findByUid(string $uid): ?array
    {
        $uid = trim($uid);
        if ($uid === '' || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT user_id, uid, updated_at FROM base_staff_bracelets WHERE uid = ? LIMIT 1'
        );
        $st->execute([$uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'user_id' => (int)$row['user_id'],
            'uid' => (string)$row['uid'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }

    public static function setForUser(int $userId, string $uid): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Invalid user id');
        }
        $uid = trim($uid);
        if ($uid === '') {
            self::clearForUser($userId);
            return;
        }
        if (strlen($uid) > 32) {
            throw new InvalidArgumentException(t('terminals.err.staff_bracelet_uid_too_long'));
        }
        if (!self::tableReady()) {
            throw new RuntimeException('base_staff_bracelets missing');
        }
        $now = time();
        $pdo = Database::pdo();
        $pdo->prepare(
            'DELETE FROM base_staff_bracelets WHERE uid = ? AND user_id <> ?'
        )->execute([$uid, $userId]);
        $pdo->prepare(
            'INSERT INTO base_staff_bracelets (user_id, uid, updated_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE uid = VALUES(uid), updated_at = VALUES(updated_at)'
        )->execute([$userId, $uid, $now]);
    }

    public static function clearForUser(int $userId): void
    {
        if ($userId < 1 || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare(
            'DELETE FROM base_staff_bracelets WHERE user_id = ?'
        )->execute([$userId]);
    }

    /**
     * All staff NFC bindings (optional name from base_users).
     *
     * @return list<array{user_id:int,uid:string,updated_at:int,name:string}>
     */
    public static function listAll(): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $hasUsers = Database::tableExists('base_users');
        if ($hasUsers) {
            $sql = 'SELECT b.user_id, b.uid, b.updated_at, COALESCE(u.name, \'\') AS name
                    FROM base_staff_bracelets b
                    LEFT JOIN base_users u ON u.id = b.user_id
                    WHERE b.uid <> \'\'
                    ORDER BY b.user_id ASC';
        } else {
            $sql = 'SELECT user_id, uid, updated_at, \'\' AS name
                    FROM base_staff_bracelets
                    WHERE uid <> \'\'
                    ORDER BY user_id ASC';
        }
        $rows = Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $uid = trim((string)($row['uid'] ?? ''));
            if ($uid === '') {
                continue;
            }
            $out[] = [
                'user_id' => (int)($row['user_id'] ?? 0),
                'uid' => $uid,
                'updated_at' => (int)($row['updated_at'] ?? 0),
                'name' => trim((string)($row['name'] ?? '')),
            ];
        }
        return $out;
    }
}
