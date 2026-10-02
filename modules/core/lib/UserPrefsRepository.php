<?php
declare(strict_types=1);

final class UserPrefsRepository
{
    public static function get(int $userId, string $key): ?string
    {
        if ($userId < 1 || $key === '' || !Database::tableExists('base_user_prefs')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT pref_value FROM base_user_prefs WHERE user_id = ? AND pref_key = ? LIMIT 1'
        );
        $st->execute([$userId, $key]);
        $val = $st->fetchColumn();
        return $val === false ? null : (string)$val;
    }

    public static function set(int $userId, string $key, string $value): void
    {
        if ($userId < 1 || $key === '' || !Database::tableExists('base_user_prefs')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_user_prefs (user_id, pref_key, pref_value, updated_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = VALUES(updated_at)'
        );
        $st->execute([$userId, $key, $value, time()]);
    }

    public static function delete(int $userId, string $key): void
    {
        if ($userId < 1 || $key === '' || !Database::tableExists('base_user_prefs')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'DELETE FROM base_user_prefs WHERE user_id = ? AND pref_key = ?'
        );
        $st->execute([$userId, $key]);
    }

    /**
     * @return mixed|null
     */
    public static function getJson(int $userId, string $key)
    {
        $raw = self::get($userId, $key);
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * @param mixed $value
     */
    public static function setJson(int $userId, string $key, $value): void
    {
        self::set($userId, $key, (string)json_encode($value, JSON_UNESCAPED_UNICODE));
    }
}
