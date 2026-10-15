<?php
declare(strict_types=1);

/**
 * Eager upsert of current-month commission total into base_payments (oper «% МЕСЯЦ YYYY»).
 * Past months are never rewritten.
 */
final class ProcentsPaymentSync
{
    /** @var array<int,string> */
    private const MONTH_RU = [
        1 => 'ЯНВАРЬ',
        2 => 'ФЕВРАЛЬ',
        3 => 'МАРТ',
        4 => 'АПРЕЛЬ',
        5 => 'МАЙ',
        6 => 'ИЮНЬ',
        7 => 'ИЮЛЬ',
        8 => 'АВГУСТ',
        9 => 'СЕНТЯБРЬ',
        10 => 'ОКТЯБРЬ',
        11 => 'НОЯБРЬ',
        12 => 'ДЕКАБРЬ',
    ];

    public static function isAvailable(): bool
    {
        return ModuleManager::isEnabled('payments')
            && class_exists('PaymentsRepository', false)
            && Database::tableExists('base_payments');
    }

    /** cfg/modules/payments.ini → auto_procent_accrual (default on). */
    public static function isAutoEnabled(): bool
    {
        return (string)Config::get('payments', 'auto_procent_accrual', '1') === '1';
    }

    /**
     * Compute current-month report and upsert payment row (for /payments eager sync).
     *
     * @return array{synced:bool,sum:float,payment_id:int|null,label:string}
     */
    public static function syncStaffCurrentMonth(int $userId): array
    {
        $empty = ['synced' => false, 'sum' => 0.0, 'payment_id' => null, 'label' => ''];
        if ($userId < 1 || !self::isAutoEnabled() || !self::isAvailable()) {
            return $empty;
        }
        if (class_exists('DayPayroll', false) && DayPayroll::isDailyPayStaff($userId)) {
            return $empty;
        }
        if (!ModuleManager::isEnabled('procents') || !class_exists('ProcentsRepository', false)) {
            return $empty;
        }
        $y = (int)date('Y');
        $m = (int)date('n');
        $report = ProcentsRepository::report($userId, $y, $m);
        return self::syncFromReport($userId, $report);
    }

    /**
     * Upsert accrual for the calendar current month only.
     *
     * @return int|null payment id, or null when skipped / unavailable
     */
    public static function upsertCurrentMonth(int $userId, float $sum): ?int
    {
        if ($userId < 1 || !self::isAutoEnabled() || !self::isAvailable()) {
            return null;
        }
        if (class_exists('DayPayroll', false) && DayPayroll::isDailyPayStaff($userId)) {
            return null;
        }
        $y = (int)date('Y');
        $m = (int)date('n');
        $descr = self::monthDescr($y, $m);
        $monthStart = (int)mktime(0, 0, 0, $m, 1, $y);
        $monthEnd = (int)mktime(23, 59, 59, $m, (int)date('t', $monthStart), $y);
        $dt = (int)mktime(0, 0, 0, $m, (int)date('t', $monthStart), $y);
        $sumRounded = round($sum);
        $typeId = self::resolveFotTypeId();

        $existing = self::findSyncedRow($userId, $descr, $monthStart, $monthEnd);
        if ($existing !== null) {
            $id = (int)$existing['id'];
            if ((float)$existing['sum'] == $sumRounded
                && (int)$existing['dt'] === $dt
                && (int)$existing['type_id'] === $typeId
                && (string)$existing['descr'] === $descr
            ) {
                return $id;
            }
            PaymentsRepository::update($id, [
                'user_id' => $userId,
                'dt' => $dt,
                'descr' => $descr,
                'direction' => 1,
                'sum' => $sumRounded,
                'type_id' => $typeId,
                'account' => 0,
                'comment_id' => (int)($existing['comment_id'] ?? 0),
                'is_partner' => (int)($existing['is_partner'] ?? 0),
                'admin_inworked' => (int)($existing['admin_inworked'] ?? 0),
                'dt_admin_inworked' => (int)($existing['dt_admin_inworked'] ?? 0),
                'admin_signed' => (int)($existing['admin_signed'] ?? 0),
                'dt_admin_signed' => (int)($existing['dt_admin_signed'] ?? 0),
                'accept_user' => (int)($existing['accept_user'] ?? 0),
                'dt_accept' => (int)($existing['dt_accept'] ?? 0),
            ]);
            return $id;
        }

        return PaymentsRepository::create([
            'user_id' => $userId,
            'dt' => $dt,
            'descr' => $descr,
            'direction' => 1,
            'sum' => $sumRounded,
            'type_id' => $typeId,
            'account' => 0,
            'dt_create' => time(),
            'user_signed' => Auth::userId() > 0 ? Auth::userId() : $userId,
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

    /**
     * Sync current month from an already-built report (no second full calculation).
     *
     * @param array{periods?:list<array<string,mixed>>} $report
     * @return array{synced:bool,sum:float,payment_id:int|null,label:string}
     */
    public static function syncFromReport(int $userId, array $report): array
    {
        $empty = ['synced' => false, 'sum' => 0.0, 'payment_id' => null, 'label' => ''];
        if ($userId < 1 || !self::isAutoEnabled() || !self::isAvailable()) {
            return $empty;
        }
        $label = date('Y-m');
        $sum = null;
        foreach ($report['periods'] ?? [] as $period) {
            if ((string)($period['label'] ?? '') === $label) {
                $sum = (float)($period['total'] ?? 0);
                break;
            }
        }
        if ($sum === null) {
            // Current month not in range (no activity) — still upsert 0 only if we already have a row? Plan: leave SUM=0.
            // Without a period, skip creating empty noise for staff with no data this month.
            return $empty;
        }
        $paymentId = self::upsertCurrentMonth($userId, $sum);
        return [
            'synced' => $paymentId !== null,
            'sum' => round($sum),
            'payment_id' => $paymentId,
            'label' => $label,
        ];
    }

    public static function monthDescr(int $year, int $month): string
    {
        $name = self::MONTH_RU[$month] ?? (string)$month;
        return '% ' . $name . ' ' . $year;
    }

    private static function resolveFotTypeId(): int
    {
        $configured = (int)Config::get('payments', 'procent_type_id', 0);
        if ($configured > 0) {
            return $configured;
        }
        if (Database::tableExists('base_payment_types')) {
            $st = Database::pdo()->prepare(
                'SELECT ID FROM base_payment_types WHERE NAME = ? ORDER BY ID ASC LIMIT 1'
            );
            $st->execute(['ФОТ']);
            $id = (int)$st->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }
        return 1;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function findSyncedRow(int $userId, string $descr, int $monthStart, int $monthEnd): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_USER AS user_id, DT AS dt, DESCR AS descr,
                    DIRECTION AS direction, SUM AS sum, ID_TYPE AS type_id, ACCOUNT AS account,
                    DT_CREATE AS dt_create, ID_USER_SIGNED AS user_signed, ID_COMMENT AS comment_id,
                    IS_PARTNER_PAYMENT AS is_partner,
                    ID_ADMIN_INWORKED AS admin_inworked, DT_ADMIN_INWORKED AS dt_admin_inworked,
                    ID_ADMIN_SIGNED AS admin_signed, DT_ADMIN_SIGNED AS dt_admin_signed,
                    ID_ACCEPT_USER_SIGNED AS accept_user, DT_ACCEPT_USER_SIGNED AS dt_accept,
                    ID_MOVEMENT_LINKED AS movement_linked
             FROM base_payments
             WHERE ID_USER = ? AND CAST(DIRECTION AS SIGNED) = 1 AND ACCOUNT = 0
               AND DESCR = ? AND DT >= ? AND DT <= ?
             ORDER BY ID DESC LIMIT 1'
        );
        $st->execute([$userId, $descr, $monthStart, $monthEnd]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
