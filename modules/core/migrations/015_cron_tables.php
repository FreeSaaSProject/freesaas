<?php
declare(strict_types=1);

/**
 * Platform cron job state + run journal (hard tick /cron/tick).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS base_cron_job_state (
            JOB_ID VARCHAR(64) NOT NULL,
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            LAST_STARTED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            LAST_FINISHED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            LAST_STATUS VARCHAR(16) NOT NULL DEFAULT \'\',
            LAST_MESSAGE VARCHAR(500) NOT NULL DEFAULT \'\',
            LAST_MS INT UNSIGNED NOT NULL DEFAULT 0,
            LAST_TICK_ID VARCHAR(32) NOT NULL DEFAULT \'\',
            PRIMARY KEY (JOB_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS base_cron_runs (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            TICK_ID VARCHAR(32) NOT NULL DEFAULT \'\',
            JOB_ID VARCHAR(64) NOT NULL DEFAULT \'\',
            STARTED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            FINISHED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            STATUS VARCHAR(16) NOT NULL DEFAULT \'\',
            MESSAGE VARCHAR(500) NOT NULL DEFAULT \'\',
            MS INT UNSIGNED NOT NULL DEFAULT 0,
            PROCESSED INT UNSIGNED NOT NULL DEFAULT 0,
            SKIPPED INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_cron_runs_started (STARTED_AT),
            KEY idx_cron_runs_job (JOB_ID, STARTED_AT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
};
