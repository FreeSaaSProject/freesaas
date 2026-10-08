<?php
declare(strict_types=1);

final class YclientsSyncStateRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_yclients_sync_state');
    }

    /** @return array<string,mixed>|null */
    public static function get(string $job): ?array
    {
        if (!self::tableReady() || $job === '') {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_yclients_sync_state WHERE job = ? LIMIT 1');
        $st->execute([$job]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string,mixed>|null $meta
     */
    public static function mark(string $job, bool $ok, string $error = '', ?array $meta = null, string $cursor = ''): void
    {
        if (!self::tableReady() || $job === '') {
            return;
        }
        $metaJson = $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
        $st = Database::pdo()->prepare(
            'INSERT INTO base_yclients_sync_state (job, cursor_value, last_run, last_ok, last_error, meta_json)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               cursor_value = VALUES(cursor_value),
               last_run = VALUES(last_run),
               last_ok = VALUES(last_ok),
               last_error = VALUES(last_error),
               meta_json = VALUES(meta_json)'
        );
        $st->execute([
            $job,
            $cursor,
            time(),
            $ok ? 1 : 0,
            $error !== '' ? $error : null,
            $metaJson,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->query('SELECT * FROM base_yclients_sync_state ORDER BY job ASC');
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
