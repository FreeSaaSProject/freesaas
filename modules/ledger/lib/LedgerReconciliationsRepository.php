<?php
declare(strict_types=1);

final class LedgerReconciliationsRepository
{
    /**
     * @return list<array<string,mixed>>
     */
    public static function listForAccount(int $accountId, int $limit = 20): array
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
             WHERE ID_ACCOUNT = ?
             ORDER BY DT_RECONCILIATION DESC, ID DESC
             LIMIT ' . max(1, $limit)
        );
        $st->execute([$accountId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function create(int $accountId, float $bankBalance, int $dt, int $userId): int
    {
        $system = LedgerMovesRepository::accountBalance($accountId);
        $st = Database::pdo()->prepare(
            'INSERT INTO base_account_reconciliations
             (ID_ACCOUNT, BANK_BALANCE, SYSTEM_BALANCE, DT_RECONCILIATION, ID_USER, DISABLED)
             VALUES (?, ?, ?, ?, ?, 0)'
        );
        $st->execute([$accountId, $bankBalance, $system, $dt > 0 ? $dt : time(), $userId]);
        return (int)Database::pdo()->lastInsertId();
    }

    public static function disable(int $id, string $reason, int $userId): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException(t('ledger.recon.err.reason'));
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_account_reconciliations
             SET DISABLED = 1, DISABLED_REASON = ?, DT_DISABLED = ?
             WHERE ID = ? AND DISABLED = 0'
        );
        $st->execute([$reason, time(), $id]);
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
