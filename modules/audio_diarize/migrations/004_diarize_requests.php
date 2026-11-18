<?php
declare(strict_types=1);

/**
 * Explicit file-diarize queue (manual / after ASR). No auto lookback.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `base_diarize_requests` (
            `ID_RECORD` INT UNSIGNED NOT NULL,
            `SOURCE` VARCHAR(32) NOT NULL DEFAULT \'\',
            `DT_REQUESTED` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`ID_RECORD`),
            KEY `idx_diarize_req_dt` (`DT_REQUESTED`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
};
