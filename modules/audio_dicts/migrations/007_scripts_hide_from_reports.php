<?php
declare(strict_types=1);

/**
 * Part flag: hide block from quality / day-report display (still used in gates).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_records_scripts')) {
        return;
    }
    if (Database::columnExists('base_records_scripts', 'HIDE_FROM_REPORTS')) {
        return;
    }
    $pdo->exec(
        'ALTER TABLE `base_records_scripts`
         ADD COLUMN `HIDE_FROM_REPORTS` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0'
    );
};
