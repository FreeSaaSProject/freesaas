<?php
declare(strict_types=1);

/**
 * KPI on script parts becomes gap seconds (0 = off), not a boolean.
 * Legacy KPI=1 → 7 (previous hardcoded CollectRunner gap).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_records_scripts')) {
        return;
    }
    if (!Database::columnExists('base_records_scripts', 'KPI')) {
        $pdo->exec(
            'ALTER TABLE `base_records_scripts`
             ADD COLUMN `KPI` INT UNSIGNED NOT NULL DEFAULT 0'
        );
        return;
    }
    // Widen TINYINT(1) → INT so values > 1 are stored.
    $pdo->exec(
        'ALTER TABLE `base_records_scripts`
         MODIFY COLUMN `KPI` INT UNSIGNED NOT NULL DEFAULT 0'
    );
    $pdo->exec('UPDATE `base_records_scripts` SET `KPI` = 7 WHERE `KPI` = 1 AND `PID` > 0');
};
