<?php
declare(strict_types=1);

final class BitrixOutboxRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_bitrix_outbox');
    }

    /** @param array<string,mixed> $payload */
    public static function enqueue(string $entity, int $localId, string $op, array $payload): void
    {
        if (!self::tableReady() || $entity === '' || $localId < 1) {
            return;
        }
        $now = time();
        Database::pdo()->prepare(
            'INSERT INTO base_bitrix_outbox (entity, local_id, op, payload_json, status, attempts, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $entity,
            $localId,
            $op !== '' ? $op : 'upsert',
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            'pending',
            0,
            $now,
            $now,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function claimPending(int $limit = 20): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $st = Database::pdo()->query(
            "SELECT id, entity, local_id, op, payload_json, attempts
             FROM base_bitrix_outbox WHERE status = 'pending' ORDER BY id ASC LIMIT {$limit}"
        );
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public static function markDone(int $id): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare(
            "UPDATE base_bitrix_outbox SET status = 'done', updated_at = ? WHERE id = ?"
        )->execute([time(), $id]);
    }

    public static function markError(int $id, string $error): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare(
            "UPDATE base_bitrix_outbox SET status = 'pending', attempts = attempts + 1,
             last_error = ?, updated_at = ? WHERE id = ?"
        )->execute([$error, time(), $id]);
    }

    public static function pendingCount(): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        return (int)Database::pdo()->query(
            "SELECT COUNT(*) FROM base_bitrix_outbox WHERE status = 'pending'"
        )->fetchColumn();
    }
}
