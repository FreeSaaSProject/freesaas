<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_backup_runs (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            STARTED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            FINISHED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            STATUS VARCHAR(24) NOT NULL DEFAULT 'running',
            PHASE VARCHAR(32) NOT NULL DEFAULT '',
            STATE_JSON MEDIUMTEXT NULL,
            BYTES BIGINT UNSIGNED NOT NULL DEFAULT 0,
            REMOTE_DIR VARCHAR(512) NOT NULL DEFAULT '',
            ERROR VARCHAR(1024) NOT NULL DEFAULT '',
            TRIGGERED_BY VARCHAR(64) NOT NULL DEFAULT 'cron',
            PRIMARY KEY (ID),
            KEY idx_status_started (STATUS, STARTED_AT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
