<?php
declare(strict_types=1);

final class LedgerReconciliationsRepository
{
    /**
     * Normalize bank-balance paste from statements / PDF (oper show_account_moves).
     */
    public static function normalizeBankBalance(string $value): string
    {
        return money_normalize_input($value);
    }

    /**
     * Latest active (non-disabled) reconciliation for an account.
     *
     * @return array<string,mixed>|null
     */
    public static function latestActive(int $accountId): ?array
    {
        if ($accountId < 1 || !Database::tableExists('base_account_reconciliations')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_ACCOUNT AS account_id, BANK_BALANCE AS bank_balance,
                    SYSTEM_BALANCE AS system_balance, DT_RECONCILIATION AS dt,
                    ID_USER AS user_id, DISABLED AS disabled, DISABLED_REASON AS reason,
                    DT_DISABLED AS dt_disabled
             FROM base_account_reconciliations
             WHERE ID_ACCOUNT = ? AND DISABLED = 0
             ORDER BY DT_RECONCILIATION DESC, ID DESC
             LIMIT 1'
        );
        $st->execute([$accountId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Active reconciliation dated today (calendar day), if any.
     *
     * @return array<string,mixed>|null
     */
    public static function latestToday(int $accountId): ?array
    {
        $todayStart = mktime(0, 0, 0) ?: time();
        return self::latestOnDay($accountId, $todayStart);
    }

    /**
     * Active reconciliation on a calendar day (local midnight → end of day).
     *
     * @return array<string,mixed>|null
     */
    public static function latestOnDay(int $accountId, int $dayStart): ?array
    {
        if ($accountId < 1 || $dayStart < 1 || !Database::tableExists('base_account_reconciliations')) {
            return null;
        }
        $dayStart = (int)(mktime(
            0,
            0,
            0,
            (int)date('n', $dayStart),
            (int)date('j', $dayStart),
            (int)date('Y', $dayStart)
        ) ?: $dayStart);
        $dayEnd = $dayStart + 86400 - 1;
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_ACCOUNT AS account_id, BANK_BALANCE AS bank_balance,
                    SYSTEM_BALANCE AS system_balance, DT_RECONCILIATION AS dt,
                    ID_USER AS user_id, DISABLED AS disabled, DISABLED_REASON AS reason,
                    DT_DISABLED AS dt_disabled
             FROM base_account_reconciliations
             WHERE ID_ACCOUNT = ? AND DISABLED = 0
               AND DT_RECONCILIATION >= ? AND DT_RECONCILIATION <= ?
             ORDER BY DT_RECONCILIATION DESC, ID DESC
             LIMIT 1'
        );
        $st->execute([$accountId, $dayStart, $dayEnd]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Active reconciliations for account with DT >= $minDt (for list attachment).
     *
     * @return list<array<string,mixed>>
     */
    public static function listActiveSince(int $accountId, int $minDt): array
    {
        if ($accountId < 1 || !Database::tableExists('base_account_reconciliations')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_ACCOUNT AS account_id, BANK_BALANCE AS bank_balance,
                    SYSTEM_BALANCE AS system_balance, DT_RECONCILIATION AS dt,
                    ID_USER AS user_id, DISABLED AS disabled, DISABLED_REASON AS reason,
                    DT_DISABLED AS dt_disabled
             FROM base_account_reconciliations
             WHERE ID_ACCOUNT = ? AND DISABLED = 0 AND DT_RECONCILIATION >= ?
             ORDER BY DT_RECONCILIATION ASC, ID ASC'
        );
        $st->execute([$accountId, max(0, $minDt)]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * All active reconciliations whose DT falls in [dayStart, dayEnd] (any account).
     *
     * @return list<array<string,mixed>>
     */
    public static function listActiveForDay(int $dayStart, int $dayEnd): array
    {
        if ($dayStart < 1 || $dayEnd < $dayStart || !Database::tableExists('base_account_reconciliations')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_ACCOUNT AS account_id, BANK_BALANCE AS bank_balance,
                    SYSTEM_BALANCE AS system_balance, DT_RECONCILIATION AS dt,
                    ID_USER AS user_id, DISABLED AS disabled, DISABLED_REASON AS reason,
                    DT_DISABLED AS dt_disabled
             FROM base_account_reconciliations
             WHERE DISABLED = 0
               AND DT_RECONCILIATION >= ? AND DT_RECONCILIATION <= ?
             ORDER BY DT_RECONCILIATION DESC, ID DESC'
        );
        $st->execute([$dayStart, $dayEnd]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function countActiveForDay(int $dayStart, int $dayEnd): int
    {
        if ($dayStart < 1 || $dayEnd < $dayStart || !Database::tableExists('base_account_reconciliations')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_account_reconciliations
             WHERE DISABLED = 0
               AND DT_RECONCILIATION >= ? AND DT_RECONCILIATION <= ?'
        );
        $st->execute([$dayStart, $dayEnd]);
        return (int)$st->fetchColumn();
    }

    /**
     * Calendar midnight (local) of the most recent day that has any active reconciliation.
     */
    public static function latestDayStartWithAny(): ?int
    {
        if (!Database::tableExists('base_account_reconciliations')) {
            return null;
        }
        $maxDt = (int)Database::pdo()->query(
            'SELECT MAX(DT_RECONCILIATION) FROM base_account_reconciliations WHERE DISABLED = 0'
        )->fetchColumn();
        if ($maxDt < 1) {
            return null;
        }
        $dayStart = mktime(0, 0, 0, (int)date('n', $maxDt), (int)date('j', $maxDt), (int)date('Y', $maxDt));
        return $dayStart !== false ? $dayStart : null;
    }

    /**
     * Midnight of the nearest calendar day with active recons strictly before $beforeDayStart.
     */
    public static function nearestDayStartBefore(int $beforeDayStart): ?int
    {
        if ($beforeDayStart < 1 || !Database::tableExists('base_account_reconciliations')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT MAX(DT_RECONCILIATION) FROM base_account_reconciliations
             WHERE DISABLED = 0 AND DT_RECONCILIATION < ?'
        );
        $st->execute([$beforeDayStart]);
        $maxDt = (int)$st->fetchColumn();
        if ($maxDt < 1) {
            return null;
        }
        $dayStart = mktime(0, 0, 0, (int)date('n', $maxDt), (int)date('j', $maxDt), (int)date('Y', $maxDt));
        return $dayStart !== false ? $dayStart : null;
    }

    /**
     * Midnight of the nearest calendar day with active recons strictly after $afterDayEnd.
     */
    public static function nearestDayStartAfter(int $afterDayEnd): ?int
    {
        if ($afterDayEnd < 1 || !Database::tableExists('base_account_reconciliations')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT MIN(DT_RECONCILIATION) FROM base_account_reconciliations
             WHERE DISABLED = 0 AND DT_RECONCILIATION > ?'
        );
        $st->execute([$afterDayEnd]);
        $minDt = (int)$st->fetchColumn();
        if ($minDt < 1) {
            return null;
        }
        $dayStart = mktime(0, 0, 0, (int)date('n', $minDt), (int)date('j', $minDt), (int)date('Y', $minDt));
        return $dayStart !== false ? $dayStart : null;
    }

    /**
     * @param int|null $dt Unix timestamp for DT_RECONCILIATION (default now)
     * @param float|null $systemBalance Snapshot to store; null = live account balance
     */
    public static function create(
        int $accountId,
        float $bankBalance,
        int $userId,
        ?int $dt = null,
        ?float $systemBalance = null
    ): int {
        if ($accountId < 1) {
            throw new InvalidArgumentException(t('ledger.moves.err.account'));
        }
        $system = $systemBalance !== null
            ? $systemBalance
            : LedgerMovesRepository::accountBalance($accountId);
        $at = $dt !== null && $dt > 0 ? $dt : time();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_account_reconciliations
             (ID_ACCOUNT, BANK_BALANCE, SYSTEM_BALANCE, DT_RECONCILIATION, ID_USER, DISABLED)
             VALUES (?, ?, ?, ?, ?, 0)'
        );
        $st->execute([$accountId, $bankBalance, $system, $at, $userId]);
        return (int)Database::pdo()->lastInsertId();
    }

    /**
     * @param bool $refreshSystem When true, rewrite SYSTEM_BALANCE to live ledger (today session).
     *                            When false, keep the snapshot from the reconciliation date.
     */
    public static function updateBank(
        int $id,
        int $accountId,
        float $bankBalance,
        bool $refreshSystem = true
    ): void {
        if ($id < 1 || $accountId < 1) {
            throw new InvalidArgumentException(t('ledger.recon.err.not_found'));
        }
        if ($refreshSystem) {
            $system = LedgerMovesRepository::accountBalance($accountId);
            $st = Database::pdo()->prepare(
                'UPDATE base_account_reconciliations
                 SET BANK_BALANCE = ?, SYSTEM_BALANCE = ?
                 WHERE ID = ? AND ID_ACCOUNT = ? AND DISABLED = 0'
            );
            $st->execute([$bankBalance, $system, $id, $accountId]);
        } else {
            $st = Database::pdo()->prepare(
                'UPDATE base_account_reconciliations
                 SET BANK_BALANCE = ?
                 WHERE ID = ? AND ID_ACCOUNT = ? AND DISABLED = 0'
            );
            $st->execute([$bankBalance, $id, $accountId]);
        }
        // MySQL "Changed: 0" when values already match — not an error.
        if ($st->rowCount() < 1) {
            $check = Database::pdo()->prepare(
                'SELECT 1 FROM base_account_reconciliations
                 WHERE ID = ? AND ID_ACCOUNT = ? AND DISABLED = 0 LIMIT 1'
            );
            $check->execute([$id, $accountId]);
            if (!$check->fetchColumn()) {
                throw new InvalidArgumentException(t('ledger.recon.err.not_found'));
            }
        }
    }

    /** Hard-delete today's reconciliation only (oper delete_reconciliation). */
    public static function deleteToday(int $id, int $accountId): void
    {
        $todayStart = mktime(0, 0, 0) ?: time();
        self::deleteInDay($id, $accountId, $todayStart, $todayStart + 86400 - 1);
    }

    /** Hard-delete a reconciliation that falls in [dayStart, dayEnd]. */
    public static function deleteInDay(int $id, int $accountId, int $dayStart, int $dayEnd): void
    {
        if ($id < 1 || $accountId < 1 || $dayStart < 1 || $dayEnd < $dayStart) {
            throw new InvalidArgumentException(t('ledger.recon.err.not_found'));
        }
        $st = Database::pdo()->prepare(
            'DELETE FROM base_account_reconciliations
             WHERE ID = ? AND ID_ACCOUNT = ? AND DISABLED = 0
               AND DT_RECONCILIATION >= ? AND DT_RECONCILIATION <= ?'
        );
        $st->execute([$id, $accountId, $dayStart, $dayEnd]);
        if ($st->rowCount() < 1) {
            throw new InvalidArgumentException(t('ledger.recon.err.not_today'));
        }
    }

    public static function disable(int $id, string $reason, int $userId): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException(t('ledger.recon.err.reason'));
        }
        $todayStart = mktime(0, 0, 0) ?: time();
        $st = Database::pdo()->prepare(
            'UPDATE base_account_reconciliations
             SET DISABLED = 1, DISABLED_REASON = ?, DT_DISABLED = ?
             WHERE ID = ? AND DISABLED = 0 AND DT_RECONCILIATION < ?'
        );
        $st->execute([$reason, time(), $id, $todayStart]);
        if ($st->rowCount() < 1) {
            throw new InvalidArgumentException(t('ledger.recon.err.not_past'));
        }
    }

    /** Refresh SYSTEM_BALANCE on today's open reconciliations for an account. */
    public static function refreshTodaySystemBalance(int $accountId): void
    {
        if ($accountId < 1 || !Database::tableExists('base_account_reconciliations')) {
            return;
        }
        $todayStart = mktime(0, 0, 0) ?: time();
        $todayEnd = $todayStart + 86400 - 1;
        $balance = LedgerMovesRepository::accountBalance($accountId);
        $st = Database::pdo()->prepare(
            'UPDATE base_account_reconciliations SET SYSTEM_BALANCE = ?
             WHERE ID_ACCOUNT = ? AND DISABLED = 0
               AND DT_RECONCILIATION >= ? AND DT_RECONCILIATION <= ?'
        );
        $st->execute([$balance, $accountId, $todayStart, $todayEnd]);
    }
}
