<?php
declare(strict_types=1);

/**
 * Cross-plugin chat FSM state (await_input, recipe wizard, …).
 * Keyed by BOT_SOURCE + platform + chat_id.
 */
final class BotChatState
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_bot_chat_state');
    }

    /**
     * @return array{module_id:string,mode:string,data:array<string,mixed>,at:int}|null
     */
    public static function get(int $source, string $platform, string $chatId): ?array
    {
        if (!self::tableReady() || $chatId === '') {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT MODULE_ID, MODE, DATA_JSON, DTUPDATE
             FROM base_bot_chat_state
             WHERE BOT_SOURCE = ? AND PLATFORM = ? AND CHAT_ID = ? LIMIT 1'
        );
        $st->execute([$source, strtolower($platform), $chatId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $data = [];
        $raw = (string)($row['DATA_JSON'] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        return [
            'module_id' => (string)($row['MODULE_ID'] ?? ''),
            'mode' => (string)($row['MODE'] ?? ''),
            'data' => $data,
            'at' => (int)($row['DTUPDATE'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function set(
        int $source,
        string $platform,
        string $chatId,
        string $moduleId,
        string $mode,
        array $data = []
    ): void {
        if (!self::tableReady() || $chatId === '') {
            return;
        }
        $now = time();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_bot_chat_state
             (BOT_SOURCE, PLATFORM, CHAT_ID, MODULE_ID, MODE, DATA_JSON, DTUPDATE)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               MODULE_ID = VALUES(MODULE_ID),
               MODE = VALUES(MODE),
               DATA_JSON = VALUES(DATA_JSON),
               DTUPDATE = VALUES(DTUPDATE)'
        );
        $st->execute([
            $source,
            strtolower($platform),
            $chatId,
            $moduleId,
            $mode,
            $json,
            $now,
        ]);
    }

    public static function clear(int $source, string $platform, string $chatId): void
    {
        if (!self::tableReady() || $chatId === '') {
            return;
        }
        $st = Database::pdo()->prepare(
            'DELETE FROM base_bot_chat_state
             WHERE BOT_SOURCE = ? AND PLATFORM = ? AND CHAT_ID = ?'
        );
        $st->execute([$source, strtolower($platform), $chatId]);
    }

    public static function isFresh(?array $state, int $ttlSec = 3600): bool
    {
        if ($state === null) {
            return false;
        }
        $at = (int)($state['at'] ?? 0);
        return $at > 0 && (time() - $at) <= $ttlSec;
    }
}
