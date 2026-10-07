<?php
declare(strict_types=1);

final class LedgerReconciliationsRepository
{
    /**
     * Normalize bank-balance paste from statements / PDF (oper show_account_moves).
     */
    public static function normalizeBankBalance(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = (string)preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $value);
        $value = (string)preg_replace('/[\p{Z}]+/u', '', $value);
        $value = (string)preg_replace('/\s+/u', '', $value);
        return str_replace(',', '.', $value);
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
        if ($accountId < 1 || !Database::tableExists('base_account_reconciliations')) {
            return null;
        }
        $todayStart = mktime(0, 0, 0) ?: time();
        $todayEnd = $todayStart + 86400 - 1;
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
        $st->execute([$accountId, $todayStart, $todayEnd]);
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

    public static function create(int $accountId, float $bankBalance, int $userId): int
    {
        if ($accountId < 1) {
            throw new InvalidArgumentException(t('ledger.moves.err.account'));
        }
        $system = LedgerMovesRepository::accountBalance($accountId);
        $st = Database::pdo()->prepare(
            'INSERT INTO base_account_reconciliations
             (ID_ACCOUNT, BANK_BALANCE, SYSTEM_BALANCE, DT_RECONCILIATION, ID_USER, DISABLED)
             VALUES (?, ?, ?, ?, ?, 0)'
        );
        $st->execute([$accountId, $bankBalance, $system, time(), $userId]);
        return (int)Database::pdo()->lastInsertId();
    }

    public static function updateBank(int $id, int $accountId, float $bankBalance): void
    {
        if ($id < 1 || $accountId < 1) {
            throw new InvalidArgumentException(t('ledger.recon.err.not_found'));
        }
        $system = LedgerMovesRepository::accountBalance($accountId);
        $st = Database::pdo()->prepare(
            'UPDATE base_account_reconciliations
             SET BANK_BALANCE = ?, SYSTEM_BALANCE = ?
             WHERE ID = ? AND ID_ACCOUNT = ? AND DISABLED = 0'
        );
        $st->execute([$bankBalance, $system, $id, $accountId]);
        if ($st->rowCount() < 1) {
            throw new InvalidArgumentException(t('ledger.recon.err.not_found'));
        }
    }

    /** Hard-delete today's reconciliation only (oper delete_reconciliation). */
    public static function deleteToday(int $id, int $accountId): void
    {
        if ($id < 1 || $accountId < 1) {
            throw new InvalidArgumentException(t('ledger.recon.err.not_found'));
        }
        $todayStart = mktime(0, 0, 0) ?: time();
        $st = Database::pdo()->prepare(
            'DELETE FROM base_account_reconciliations
             WHERE ID = ? AND ID_ACCOUNT = ? AND DT_RECONCILIATION >= ?'
        );
        $st->execute([$id, $accountId, $todayStart]);
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
