<?php
declare(strict_types=1);

/**
 * Oper /t employee-as-client settlement: debit staff sheet when they book themselves.
 * DESCR = "Оплата процедуры: ID: {procedureId}" (legacy parity). ACCOUNT=0, no DPS.
 * Cancel does not reverse (oper api_terminal_process_actions.php).
 */
final class EmployeeProcedureSettle
{
    public static function descrForProcedure(int $procedureId): string
    {
        return 'Оплата процедуры: ID: ' . max(0, $procedureId);
    }

    /**
     * Create DIRECTION=-1 payment for the staff user after an employee floor book.
     *
     * @return int|null payment id, or null if skipped / already exists / unavailable
     */
    public static function applyOnBook(int $procedureId, int $staffUserId, float $sum, int $dtStart): ?int
    {
        if ($procedureId < 1 || $staffUserId < 1) {
            return null;
        }
        if (!class_exists(LikespaConfig::class, false) || !LikespaConfig::employeeSettleEnabled()) {
            return null;
        }
        if (!ModuleManager::isEnabled('payments') || !class_exists(PaymentsRepository::class, false)) {
            return null;
        }
        if (!Database::tableExists('base_payments')) {
            return null;
        }

        $descr = self::descrForProcedure($procedureId);
        if (PaymentsRepository::existsByDescr($descr)) {
            return null;
        }

        $dt = $dtStart > 0 ? $dtStart : time();
        return PaymentsRepository::create([
            'user_id' => $staffUserId,
            'dt' => $dt,
            'descr' => $descr,
            'direction' => -1,
            'sum' => $sum,
            'type_id' => LikespaConfig::employeeSettlePaymentType(),
            'account' => 0,
            'dt_create' => $dt,
            'user_signed' => 0,
            'comment_id' => 0,
            'is_partner' => 0,
            'admin_inworked' => 0,
            'dt_admin_inworked' => 0,
            'admin_signed' => 0,
            'dt_admin_signed' => 0,
            'accept_user' => 0,
            'dt_accept' => 0,
            'movement_linked' => 0,
        ]);
    }
}
