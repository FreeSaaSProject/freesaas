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
     * @param array{
     *   confirm_mode?:string,
     *   required_permission?:string,
     *   group_chat_id?:string,
     *   group_platform?:string,
     *   plan_id?:string,
     *   plan_step?:int
     * } $meta
     * @return array{token:string,op_id:string,summary:string,expires_at:int,confirm_mode:string,required_permission:string,plan_id:string,plan_step:int}|null
     */
    public static function create(int $userId, string $opId, array $args, string $summary, array $meta = []): ?array
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
        $summary = trim($summary);
        if (function_exists('mb_substr')) {
            $summary = mb_substr($summary, 0, 4000);
        } else {
            $summary = substr($summary, 0, 4000);
        }
        $confirmMode = strtolower(trim((string)($meta['confirm_mode'] ?? 'self')));
        if ($confirmMode !== 'capability') {
            $confirmMode = 'self';
        }
        $reqPerm = trim((string)($meta['required_permission'] ?? ''));
        $groupChat = trim((string)($meta['group_chat_id'] ?? ''));
        $groupPlatform = trim((string)($meta['group_platform'] ?? ''));
        $planId = substr(trim((string)($meta['plan_id'] ?? '')), 0, 32);
        $planStep = max(0, (int)($meta['plan_step'] ?? 0));
        $hasGroupCols = Database::columnExists('base_assistant_pending', 'CONFIRM_MODE');
        $hasPlanCols = $hasGroupCols && Database::columnExists('base_assistant_pending', 'PLAN_ID');
        if ($hasPlanCols) {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_assistant_pending
                 (TOKEN, ID_USER, OP_ID, ARGS_JSON, SUMMARY, STATUS, CREATED_AT, EXPIRES_AT,
                  CONFIRM_MODE, REQUIRED_PERMISSION, GROUP_CHAT_ID, GROUP_PLATFORM, PLAN_ID, PLAN_STEP)
                 VALUES (?, ?, ?, ?, ?, \'pending\', ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $token, $userId, $opId, $argsJson, $summary, $now, $expires,
                $confirmMode, $reqPerm, $groupChat, $groupPlatform, $planId, $planStep,
            ]);
        } elseif ($hasGroupCols) {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_assistant_pending
                 (TOKEN, ID_USER, OP_ID, ARGS_JSON, SUMMARY, STATUS, CREATED_AT, EXPIRES_AT,
                  CONFIRM_MODE, REQUIRED_PERMISSION, GROUP_CHAT_ID, GROUP_PLATFORM)
                 VALUES (?, ?, ?, ?, ?, \'pending\', ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $token, $userId, $opId, $argsJson, $summary, $now, $expires,
                $confirmMode, $reqPerm, $groupChat, $groupPlatform,
            ]);
        } else {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_assistant_pending
                 (TOKEN, ID_USER, OP_ID, ARGS_JSON, SUMMARY, STATUS, CREATED_AT, EXPIRES_AT)
                 VALUES (?, ?, ?, ?, ?, \'pending\', ?, ?)'
            );
            $st->execute([$token, $userId, $opId, $argsJson, $summary, $now, $expires]);
        }
        return [
            'token' => $token,
            'op_id' => $opId,
            'summary' => $summary,
            'expires_at' => $expires,
            'confirm_mode' => $confirmMode,
            'required_permission' => $reqPerm,
            'plan_id' => $hasPlanCols ? $planId : '',
            'plan_step' => $hasPlanCols ? $planStep : 0,
        ];
    }

    /**
     * Earlier plan steps that are not successfully done. A step may run only when every
     * predecessor is "confirmed" (statuses only move forward, so that read is final).
     * open   = still may succeed (pending not expired, executing) → wait;
     * broken = will never succeed (cancelled, expired, failed, pending past expiry) → plan aborted.
     *
     * @return array{open:int,broken:int}
     */
    public static function earlierStepsState(string $planId, int $planStep): array
    {
        $planId = trim($planId);
        if ($planId === '' || $planStep < 2 || !self::tableReady()
            || !Database::columnExists('base_assistant_pending', 'PLAN_ID')
        ) {
            return ['open' => 0, 'broken' => 0];
        }
        $now = time();
        $st = Database::pdo()->prepare(
            "SELECT
                COALESCE(SUM(STATUS <> 'confirmed'), 0) AS not_done,
                COALESCE(SUM(STATUS = 'executing' OR (STATUS = 'pending' AND EXPIRES_AT > ?)), 0) AS open_n
             FROM base_assistant_pending
             WHERE PLAN_ID = ? AND PLAN_STEP > 0 AND PLAN_STEP < ?"
        );
        $st->execute([$now, $planId, $planStep]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $notDone = (int)($row['not_done'] ?? 0);
        $open = (int)($row['open_n'] ?? 0);
        return ['open' => $open, 'broken' => max(0, $notDone - $open)];
    }

    /**
     * All rows of one plan in step order (any status).
     *
     * @return list<array<string,mixed>>
     */
    public static function listByPlan(string $planId): array
    {
        $planId = trim($planId);
        if ($planId === '' || !self::tableReady()
            || !Database::columnExists('base_assistant_pending', 'PLAN_ID')
        ) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_assistant_pending
             WHERE PLAN_ID = ?
             ORDER BY PLAN_STEP ASC, CREATED_AT ASC, ID ASC
             LIMIT 50'
        );
        $st->execute([$planId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
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

    /** Cancel all pending writes for a user (e.g. dialog clear). @return int */
    public static function cancelAllPendingForUser(int $userId): int
    {
        if ($userId < 1 || !self::tableReady()) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_assistant_pending
             SET STATUS = \'cancelled\', RESOLVED_AT = ?
             WHERE ID_USER = ? AND STATUS = \'pending\''
        );
        $st->execute([time(), $userId]);
        return $st->rowCount();
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

    /**
     * Atomically take a pending row before invoke (pending → executing).
     * Only one concurrent confirmer wins; loser must not run the write.
     *
     * @return array<string,mixed>|null claimed row, or null if lost race / missing / expired
     */
    public static function claimForExecution(string $token, int $confirmedBy): ?array
    {
        $token = trim($token);
        if ($token === '' || !self::tableReady()) {
            return null;
        }
        self::failStaleExecuting($token);

        $now = time();
        $set = 'STATUS = \'executing\', RESOLVED_AT = ?';
        $params = [$now];
        if ($confirmedBy > 0 && Database::columnExists('base_assistant_pending', 'CONFIRMED_BY')) {
            $set .= ', CONFIRMED_BY = ?';
            $params[] = $confirmedBy;
        }
        $params[] = $token;
        $params[] = $now;
        $st = Database::pdo()->prepare(
            'UPDATE base_assistant_pending
             SET ' . $set . '
             WHERE TOKEN = ? AND STATUS = \'pending\' AND EXPIRES_AT > ?'
        );
        $st->execute($params);
        if ($st->rowCount() < 1) {
            return null;
        }
        return self::findByToken($token);
    }

    /**
     * Finish a claimed row (executing → confirmed|failed).
     *
     * @param array<string,mixed>|null $result
     */
    public static function finalizeExecution(string $token, string $status, $result = null): bool
    {
        $token = trim($token);
        $status = strtolower(trim($status));
        if ($token === '' || !in_array($status, ['confirmed', 'failed'], true) || !self::tableReady()) {
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
             WHERE TOKEN = ? AND STATUS = \'executing\''
        );
        $st->execute([$status, $json, time(), $token]);
        return $st->rowCount() > 0;
    }

    /** Mark hung executing rows failed so they do not block forever (crash mid-invoke). */
    public static function failStaleExecuting(string $token, int $maxAgeSec = 300): void
    {
        $token = trim($token);
        if ($token === '' || !self::tableReady()) {
            return;
        }
        $maxAgeSec = max(60, $maxAgeSec);
        $cutoff = time() - $maxAgeSec;
        $st = Database::pdo()->prepare(
            'UPDATE base_assistant_pending
             SET STATUS = \'failed\', RESULT_JSON = ?, RESOLVED_AT = ?
             WHERE TOKEN = ? AND STATUS = \'executing\' AND RESOLVED_AT > 0 AND RESOLVED_AT < ?'
        );
        $st->execute([
            '{"error":"stale executing"}',
            time(),
            $token,
            $cutoff,
        ]);
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

    /** Latest pending write bound to a Max/Telegram group chat. */
    public static function findLatestPendingForGroup(string $groupChatId, string $platform = 'max'): ?array
    {
        $groupChatId = trim($groupChatId);
        $platform = strtolower(trim($platform));
        if ($groupChatId === '' || !self::tableReady()) {
            return null;
        }
        if (!Database::columnExists('base_assistant_pending', 'GROUP_CHAT_ID')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_assistant_pending
             WHERE GROUP_CHAT_ID = ? AND GROUP_PLATFORM = ? AND STATUS = \'pending\' AND EXPIRES_AT > ?
             ORDER BY CREATED_AT DESC, ID DESC
             LIMIT 1'
        );
        $st->execute([$groupChatId, $platform, time()]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @deprecated Prefer claimForExecution + finalizeExecution (atomic before invoke).
     * @param array<string,mixed>|null $result
     */
    public static function markConfirmedBy(string $token, int $confirmedBy, $result = null): bool
    {
        if (self::finalizeExecution($token, 'confirmed', $result)) {
            return true;
        }
        // Legacy path: still pending (tests / old callers that skipped claim).
        if (!self::mark($token, 'confirmed', $result)) {
            return false;
        }
        if ($confirmedBy > 0 && Database::columnExists('base_assistant_pending', 'CONFIRMED_BY')) {
            $st = Database::pdo()->prepare(
                'UPDATE base_assistant_pending SET CONFIRMED_BY = ? WHERE TOKEN = ?'
            );
            $st->execute([$confirmedBy, $token]);
        }
        return true;
    }
}
