<?php
declare(strict_types=1);

/**
 * One-shot: delete past-month day accruals («Начисление ЗП ФЛ» / ACCRUAL_KEY day:*)
 * for calendar months that also have a monthly «% МЕСЯЦ YYYY» line for the same staff.
 *
 * Happened when staff briefly got PAY_DAILY and DayPayroll backfilled ~180 days while
 * monthly % payroll continued — unpaid day lines inflated «org owes» balance.
 *
 * Idempotent. Does not touch the current calendar month. Pure PAY_DAILY history
 * (no monthly % that month) is kept. After all envs have run this, remove the migrator.
 *
 * @return array{ok:bool,message:string,deleted:int,sum:float,users:array<int,array{n:int,sum:float}>}
 */
return static function (PDO $pdo, array $spec): array {
    if (!Database::tableExists('base_payments')) {
        return [
            'ok' => true,
            'message' => 'base_payments missing',
            'deleted' => 0,
            'sum' => 0.0,
            'users' => [],
        ];
    }

    $monthStart = (int)mktime(0, 0, 0, (int)date('n'), 1, (int)date('Y'));
    $hasKey = Database::columnExists('base_payments', 'ACCRUAL_KEY');
    $dayCond = $hasKey
        ? "(p.DESCR LIKE 'Начисление ЗП ФЛ %' OR p.ACCRUAL_KEY LIKE 'day:%')"
        : "p.DESCR LIKE 'Начисление ЗП ФЛ %'";
    $monthCond = $hasKey
        ? "(m.DESCR LIKE '\\% %' OR m.ACCRUAL_KEY LIKE 'month:%')"
        : "m.DESCR LIKE '\\% %'";

    $sql = "SELECT p.ID AS id, p.ID_USER AS user_id, p.SUM AS sum
            FROM base_payments p
            WHERE CAST(p.DIRECTION AS SIGNED) > 0
              AND p.DT < ?
              AND $dayCond
              AND EXISTS (
                SELECT 1 FROM base_payments m
                WHERE m.ID_USER = p.ID_USER
                  AND CAST(m.DIRECTION AS SIGNED) > 0
                  AND FROM_UNIXTIME(m.DT, '%Y-%m') = FROM_UNIXTIME(p.DT, '%Y-%m')
                  AND $monthCond
              )
            ORDER BY p.ID ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$monthStart]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $deleted = 0;
    $sum = 0.0;
    /** @var array<int,array{n:int,sum:float}> $users */
    $users = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        $uid = (int)($row['user_id'] ?? 0);
        $rowSum = round((float)($row['sum'] ?? 0), 2);
        if ($id < 1) {
            continue;
        }
        if (class_exists('PaymentsRepository', false)) {
            PaymentsRepository::forceDelete($id);
        } else {
            $pdo->prepare('DELETE FROM base_payments WHERE ID = ?')->execute([$id]);
        }
        $deleted++;
        $sum += $rowSum;
        if (!isset($users[$uid])) {
            $users[$uid] = ['n' => 0, 'sum' => 0.0];
        }
        $users[$uid]['n']++;
        $users[$uid]['sum'] = round($users[$uid]['sum'] + $rowSum, 2);
    }

    return [
        'ok' => true,
        'message' => $deleted === 0
            ? 'No overlapping past day accruals to purge'
            : ('Purged ' . $deleted . ' day accrual(s), sum ' . round($sum, 2)),
        'deleted' => $deleted,
        'sum' => round($sum, 2),
        'users' => $users,
    ];
};
