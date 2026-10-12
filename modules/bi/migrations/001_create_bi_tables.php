<?php
declare(strict_types=1);

/**
 * BI shares + family recipe defs (single initial schema).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_bi_shares (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            TOKEN VARCHAR(64) NOT NULL,
            FAMILY VARCHAR(64) NOT NULL,
            AUDIENCE VARCHAR(16) NOT NULL DEFAULT 'guest',
            TTL_KIND VARCHAR(8) NOT NULL DEFAULT '1d',
            DT_EXPIRE INT UNSIGNED NOT NULL DEFAULT 0,
            PARAMS_JSON TEXT NOT NULL,
            STAFF_USER_IDS TEXT NOT NULL,
            LABEL VARCHAR(255) NOT NULL DEFAULT '',
            ID_CREATOR INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            REVOKED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            RUN_COUNT INT UNSIGNED NOT NULL DEFAULT 0,
            LAST_RUN_AT INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_token (TOKEN),
            KEY idx_expire (DT_EXPIRE),
            KEY idx_family (FAMILY)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_bi_family_defs (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            FAMILY_ID VARCHAR(64) NOT NULL,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            RECIPE_JSON MEDIUMTEXT NOT NULL,
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            BUILTIN_FORK VARCHAR(64) NOT NULL DEFAULT '',
            LAST_VALIDATE_OK TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            LAST_VALIDATE_MSG TEXT NOT NULL,
            ID_CREATOR INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_family_id (FAMILY_ID),
            KEY idx_enabled (ENABLED)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
