<?php
declare(strict_types=1);

final class BitrixSyncStateRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_bitrix_sync_state');
    }

    public static function get(string $job): ?array
    {
        if (!self::tableReady() || $job === '') {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT job, cursor_value, last_run, last_ok, last_error, meta_json
             FROM base_bitrix_sync_state WHERE job = ? LIMIT 1'
        );
        $st->execute([$job]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function mark(string $job, bool $ok, string $cursor = '', string $error = '', ?array $meta = null): void
    {
        if (!self::tableReady() || $job === '') {
            return;
        }
        Database::pdo()->prepare(
            'INSERT INTO base_bitrix_sync_state (job, cursor_value, last_run, last_ok, last_error, meta_json)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE cursor_value = VALUES(cursor_value), last_run = VALUES(last_run),
               last_ok = VALUES(last_ok), last_error = VALUES(last_error), meta_json = VALUES(meta_json)'
        )->execute([
            $job,
            $cursor,
            time(),
            $ok ? 1 : 0,
            $error !== '' ? $error : null,
            $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
