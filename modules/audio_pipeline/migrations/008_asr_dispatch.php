<?php
declare(strict_types=1);

/**
 * ASR dispatch: one active lease per base_records row across pull + push channels,
 * and the registry of push (inbound HTTPS) workers.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_asr_leases')) {
        $pdo->exec(
            'CREATE TABLE `base_asr_leases` (
                `RECORD_ID` INT UNSIGNED NOT NULL,
                `CHANNEL` VARCHAR(8) NOT NULL DEFAULT \'pull\',
                `WORKER_ID` INT UNSIGNED NOT NULL DEFAULT 0,
                `JOB_ID` VARCHAR(40) NOT NULL DEFAULT \'\',
                `LEASED_UNTIL` INT UNSIGNED NOT NULL DEFAULT 0,
                `ATTEMPTS` INT UNSIGNED NOT NULL DEFAULT 0,
                `DT_LEASED` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`RECORD_ID`),
                KEY `idx_asr_lease_worker` (`WORKER_ID`, `LEASED_UNTIL`),
                KEY `idx_asr_lease_job` (`JOB_ID`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
    if (!Database::tableExists('base_asr_push_workers')) {
        $pdo->exec(
            'CREATE TABLE `base_asr_push_workers` (
                `ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `NAME` VARCHAR(100) NOT NULL DEFAULT \'\',
                `BASE_URL` VARCHAR(255) NOT NULL DEFAULT \'\',
                `SECRET` VARCHAR(128) NOT NULL DEFAULT \'\',
                `SECRET_NEXT` VARCHAR(128) NOT NULL DEFAULT \'\',
                `PIN_SHA256` VARCHAR(64) NOT NULL DEFAULT \'\',
                `ENABLED` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `MAX_INFLIGHT` INT UNSIGNED NOT NULL DEFAULT 20,
                `LAST_SEEN` INT UNSIGNED NOT NULL DEFAULT 0,
                `LAST_STATUS` TEXT NULL,
                `LAST_ERROR` VARCHAR(500) NOT NULL DEFAULT \'\',
                `FAILS` INT UNSIGNED NOT NULL DEFAULT 0,
                `BACKOFF_UNTIL` INT UNSIGNED NOT NULL DEFAULT 0,
                `DT_CREATED` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`ID`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
};
