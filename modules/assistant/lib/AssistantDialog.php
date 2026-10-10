<?php
declare(strict_types=1);

/**
 * Canonical multi-channel assistant history (web /assistant + messenger bots).
 * Keyed by staff user id — not by messenger chat id.
 */
final class AssistantDialog
{
    public const MAX_TURNS = 12;

    public static function tableReady(): bool
    {
        return Database::tableExists('base_assistant_dialog');
    }

    /**
     * @return list<array{role:string,content:string}>
     */
    public static function getHistory(int $userId): array
    {
        if ($userId < 1 || !self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT HISTORY_JSON FROM base_assistant_dialog WHERE ID_USER = ? LIMIT 1'
        );
        $st->execute([$userId]);
        $raw = (string)($st->fetchColumn() ?: '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return self::normalizeMessages($decoded);
    }

    /**
     * @param list<array{role:string,content:string}> $history
     */
    public static function saveHistory(int $userId, array $history): void
    {
        if ($userId < 1 || !self::tableReady()) {
            return;
        }
        $history = self::trim(self::normalizeMessages($history));
        $json = json_encode($history, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '[]';
        }
        $now = time();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_assistant_dialog (ID_USER, HISTORY_JSON, DTUPDATE)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE HISTORY_JSON = VALUES(HISTORY_JSON), DTUPDATE = VALUES(DTUPDATE)'
        );
        $st->execute([$userId, $json, $now]);
    }

    public static function clear(int $userId): void
    {
        if ($userId < 1 || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM base_assistant_dialog WHERE ID_USER = ?')->execute([$userId]);
    }

    /** Clear shared history and cancel pending write confirms. */
    public static function clearSession(int $userId): void
    {
        self::clear($userId);
        if (class_exists('PendingActionsRepository', false)) {
            PendingActionsRepository::cancelAllPendingForUser($userId);
        }
    }

    /**
     * @param mixed $raw
     * @return list<array{role:string,content:string}>
     */
    public static function normalizeMessages($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = trim((string)($m['role'] ?? ''));
            $content = trim((string)($m['content'] ?? ''));
            if (($role !== 'user' && $role !== 'assistant') || $content === '') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }
        return $out;
    }

    /**
     * @param list<array{role:string,content:string}> $history
     * @return list<array{role:string,content:string}>
     */
    public static function trim(array $history): array
    {
        $max = self::MAX_TURNS * 2;
        if (count($history) <= $max) {
            return $history;
        }
        return array_values(array_slice($history, -$max));
    }
}
