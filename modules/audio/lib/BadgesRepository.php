<?php
declare(strict_types=1);

/**
 * Dictaphone badge dictionary (CODE = legacy ID_DICTOPHONE / ID_BADGE).
 */
final class BadgesRepository
{
    /**
     * Upsert badge from a dictophone code seen on a record.
     */
    public static function upsertFromCode(string $code, ?int $seenAt = null): void
    {
        $code = trim($code);
        if ($code === '' || $code === '0') {
            return;
        }
        if (!Database::tableExists('base_records_badges')) {
            return;
        }
        $now = $seenAt ?? time();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_records_badges (CODE, NAME, ENABLED, DT_FIRST_SEEN, DT_CREATE, DT_UPDATE)
             VALUES (?, ?, 1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               DT_UPDATE = VALUES(DT_UPDATE)'
        );
        $st->execute([$code, $code, $now, $now, $now]);
    }

    /** @return list<array<string,mixed>> */
    public static function listAll(bool $enabledOnly = false): array
    {
        if (!Database::tableExists('base_records_badges')) {
            return [];
        }
        $sql = 'SELECT ID, CODE, NAME, ENABLED, DT_FIRST_SEEN, DT_CREATE, DT_UPDATE
                FROM base_records_badges';
        if ($enabledOnly) {
            $sql .= ' WHERE ENABLED = 1';
        }
        $sql .= ' ORDER BY NAME ASC, CODE ASC';
        return Database::pdo()->query($sql)->fetchAll() ?: [];
    }

    /** @return ?array<string,mixed> */
    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT ID, CODE, NAME, ENABLED, DT_FIRST_SEEN, DT_CREATE, DT_UPDATE
             FROM base_records_badges WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function updateMeta(int $id, string $name, bool $enabled): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_records_badges SET NAME = ?, ENABLED = ?, DT_UPDATE = ? WHERE ID = ?'
        );
        $st->execute([trim($name), $enabled ? 1 : 0, time(), $id]);
    }

    /** Backfill from DISTINCT ID_DICTOPHONE on recent records. */
    public static function syncFromRecords(int $sinceTs = 0): int
    {
        if (!Database::tableExists('base_records') || !Database::tableExists('base_records_badges')) {
            return 0;
        }
        $sql = 'SELECT DISTINCT ID_DICTOPHONE FROM base_records WHERE ID_DICTOPHONE > 0';
        $params = [];
        if ($sinceTs > 0) {
            $sql .= ' AND DT_RECORDED > ?';
            $params[] = $sinceTs;
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $n = 0;
        while ($row = $st->fetch()) {
            self::upsertFromCode((string)$row['ID_DICTOPHONE']);
            $n++;
        }
        return $n;
    }
}
