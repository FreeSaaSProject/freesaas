<?php
declare(strict_types=1);

/**
 * Per-position staff quality report profiles (traffic-light day reports).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_staff_report_profiles (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_POSITION INT UNSIGNED NOT NULL DEFAULT 0,
            ENABLED TINYINT UNSIGNED NOT NULL DEFAULT 0,
            CONFIG MEDIUMTEXT NOT NULL,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_position (ID_POSITION),
            KEY idx_enabled (ENABLED)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
