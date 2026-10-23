<?php
declare(strict_types=1);

/**
 * Summary report profiles.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_summarys (
            ID INT NOT NULL AUTO_INCREMENT,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            ID_POSITION INT NOT NULL DEFAULT 0,
            CONFIG_JSON TEXT NOT NULL,
            LABELS_JSON TEXT NOT NULL,
            IS_DEFAULT TINYINT(1) NOT NULL DEFAULT 0,
            ENABLED TINYINT(1) NOT NULL DEFAULT 1,
            ID_CREATOR INT NOT NULL DEFAULT 0,
            DT_CREATE INT NOT NULL DEFAULT 0,
            DT_UPDATE INT NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_enabled_default (ENABLED, IS_DEFAULT),
            KEY idx_position (ID_POSITION)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Day bounds live in cfg/modules/audio_reports.ini (see 002_day_bounds_from_base_system).
};
