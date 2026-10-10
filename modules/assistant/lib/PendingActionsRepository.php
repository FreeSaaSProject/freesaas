<?php
declare(strict_types=1);

/** Pending write-tool confirmations for AssistantHarness. */
final class PendingActionsRepository
{
    public const TTL_SEC = 900;

    public static function tableReady(): bool
    {
        return Database::tableExists('base_assistant_pending');
    }

    /**
     * @param array<string,mixed> $args
     * @return array{token:string,op_id:string,summary:string,expires_at:int}|null
     */
    public static function create(int $userId, string $opId, array $args, string $summary): ?array
    {
        if ($userId < 1 || $opId === '' || !self::tableReady()) {
            return null;
        }
        $token = bin2hex(random_bytes(8));
        $now = time();
        $expires = $now + self::TTL_SEC;
        $argsJson = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($argsJson === false) {
            $argsJson = '{}';
        }
        $summary = mb_substr(trim($summary), 0, 500);
        $st = Database::pdo()->prepare(
            'INSERT INTO base_assistant_pending
             (TOKEN, ID_USER, OP_ID, ARGS_JSON, SUMMARY, STATUS, CREATED_AT, EXPIRES_AT)
             VALUES (?, ?, ?, ?, ?, \'pending\', ?, ?)'
        );
        $st->execute([$token, $userId, $opId, $argsJson, $summary, $now, $expires]);
        return [
            'token' => $token,
            'op_id' => $opId,
            'summary' => $summary,
            'expires_at' => $expires,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function findByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_assistant_pending WHERE TOKEN = ? LIMIT 1'
        );
        $st->execute([$token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Latest pending writes for a user (not expired).
     *
     * @return list<array<string,mixed>>
     */
    public static function listPendingForUser(int $userId, int $limit = 5): array
    {
        if ($userId < 1 || !self::tableReady()) {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_assistant_pending
             WHERE ID_USER = ? AND STATUS = \'pending\' AND EXPIRES_AT > ?
             ORDER BY CREATED_AT DESC, ID DESC
             LIMIT ' . $limit
        );
        $st->execute([$userId, time()]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string,mixed>|null $result
     */
    public static function mark(string $token, string $status, $result = null): bool
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['confirmed', 'cancelled', 'expired', 'failed'], true)) {
            return false;
        }
        if (!self::tableReady()) {
            return false;
        }
        $json = null;
        if (is_array($result)) {
            $enc = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $json = $enc !== false ? $enc : null;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_assistant_pending
             SET STATUS = ?, RESULT_JSON = ?, RESOLVED_AT = ?
             WHERE TOKEN = ? AND STATUS = \'pending\''
        );
        $st->execute([$status, $json, time(), $token]);
        return $st->rowCount() > 0;
    }

    /** @return array<string,mixed> */
    public static function decodeArgs(array $row): array
    {
        $raw = (string)($row['ARGS_JSON'] ?? '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
