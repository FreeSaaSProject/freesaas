<?php
declare(strict_types=1);

/**
 * Terminal scan log (oper patch 486). Shared with likespa.hm terminal API when present.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_term_scans (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_SCAN INT UNSIGNED NOT NULL DEFAULT 0,
            SCAN_DAY INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
            ID_TERMINAL INT UNSIGNED NOT NULL DEFAULT 0,
            BRACELET_UID VARCHAR(32) NOT NULL DEFAULT '',
            ID_1C VARCHAR(64) NOT NULL DEFAULT '',
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            PHONE VARCHAR(16) NOT NULL DEFAULT '',
            PHONE_1C VARCHAR(16) NOT NULL DEFAULT '',
            IS_PROMOTER TINYINT UNSIGNED NOT NULL DEFAULT 0,
            DISCOUNT_ACTIVATED TINYINT UNSIGNED NOT NULL DEFAULT 0,
            ID_PROMOTER INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_scan_day_admin (SCAN_DAY, ID_ADMIN),
            KEY idx_scan_day_1c (SCAN_DAY, ID_1C),
            KEY idx_scan_day_user (SCAN_DAY, ID_USER),
            KEY idx_scan_day_phone (SCAN_DAY, PHONE),
            KEY idx_dt_scan (DT_SCAN)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
