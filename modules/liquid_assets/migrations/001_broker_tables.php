<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_tinvest_accounts (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_FUND INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'owner user id',
            TOKEN MEDIUMTEXT NOT NULL,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_fund (ID_FUND),
            KEY idx_user (ID_USER)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_broker_snapshots (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_TINVEST INT UNSIGNED NOT NULL DEFAULT 0,
            ID_FUND INT UNSIGNED NOT NULL DEFAULT 0,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            TOTAL_VALUE DOUBLE NOT NULL DEFAULT 0,
            SOURCE VARCHAR(32) NOT NULL DEFAULT 'api',
            RAW_JSON MEDIUMTEXT NOT NULL,
            ID_USER_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_tinvest_dt (ID_TINVEST, DT),
            KEY idx_fund_dt (ID_FUND, DT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_broker_positions (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_SNAPSHOT INT UNSIGNED NOT NULL DEFAULT 0,
            TICKER VARCHAR(64) NOT NULL DEFAULT '',
            FIGI VARCHAR(64) NOT NULL DEFAULT '',
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            ASSET_CLASS VARCHAR(32) NOT NULL DEFAULT 'other',
            QTY DOUBLE NOT NULL DEFAULT 0,
            PRICE DOUBLE NOT NULL DEFAULT 0,
            VALUE DOUBLE NOT NULL DEFAULT 0,
            AVG_PRICE DOUBLE NOT NULL DEFAULT 0,
            META MEDIUMTEXT NOT NULL,
            PRIMARY KEY (ID),
            KEY idx_snapshot (ID_SNAPSHOT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
