<?php
declare(strict_types=1);

/**
 * Shared bookable resources (equipment, rooms capacity, etc.) + product links + allocations.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_resources (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            NOTES VARCHAR(255) NOT NULL DEFAULT '',
            QTY INT UNSIGNED NOT NULL DEFAULT 1,
            ENABLED TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (ID),
            KEY idx_resources_enabled (ENABLED)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_product_resources (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_PRODUCT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_RESOURCE INT UNSIGNED NOT NULL DEFAULT 0,
            QTY_NEEDED INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_product_resource (ID_PRODUCT, ID_RESOURCE),
            KEY idx_pr_resource (ID_RESOURCE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_resource_allocations (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_RESOURCE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_START INT UNSIGNED NOT NULL DEFAULT 0,
            DT_END INT UNSIGNED NOT NULL DEFAULT 0,
            QTY_USED INT UNSIGNED NOT NULL DEFAULT 1,
            SOURCE VARCHAR(16) NOT NULL DEFAULT '',
            SOURCE_ID INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_ra_resource_time (ID_RESOURCE, DT_START, DT_END),
            KEY idx_ra_source (SOURCE, SOURCE_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
