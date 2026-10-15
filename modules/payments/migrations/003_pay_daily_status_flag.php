<?php
declare(strict_types=1);

/**
 * Role flag: daily payroll (поденный расчёт) instead of monthly % MONTH accrual.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_admin_statuses')) {
        return;
    }
    if (!Database::columnExists('base_admin_statuses', 'PAY_DAILY')) {
        $pdo->exec(
            'ALTER TABLE `base_admin_statuses`
             ADD COLUMN `PAY_DAILY` TINYINT(1) NOT NULL DEFAULT 0'
        );
        Database::clearSchemaCache();
    }
    // likespa / oper: status id 18 was freelance/daily staff
    $pdo->exec('UPDATE base_admin_statuses SET PAY_DAILY = 1 WHERE ID = 18 AND PAY_DAILY < 1');
};
