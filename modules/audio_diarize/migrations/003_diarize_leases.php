<?php
declare(strict_types=1);

/**
 * Push/pull leases for embed samples and full-file diarize jobs.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `base_diarize_leases` (
            `KIND` VARCHAR(16) NOT NULL,
            `REF_ID` INT UNSIGNED NOT NULL,
            `CHANNEL` VARCHAR(8) NOT NULL DEFAULT \'pull\',
            `WORKER_ID` INT UNSIGNED NOT NULL DEFAULT 0,
            `JOB_ID` VARCHAR(40) NOT NULL DEFAULT \'\',
            `LEASED_UNTIL` INT UNSIGNED NOT NULL DEFAULT 0,
            `ATTEMPTS` INT UNSIGNED NOT NULL DEFAULT 0,
            `DT_LEASED` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`KIND`, `REF_ID`),
            KEY `idx_diarize_lease_worker` (`WORKER_ID`, `LEASED_UNTIL`),
            KEY `idx_diarize_lease_job` (`JOB_ID`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
};
