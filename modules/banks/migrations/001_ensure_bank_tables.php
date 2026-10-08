<?php
declare(strict_types=1);

/**
 * Bank OAuth connections, account links to ledger, statement→move idempotency map.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_bank_connections (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            PROVIDER VARCHAR(32) NOT NULL DEFAULT '',
            STATUS VARCHAR(32) NOT NULL DEFAULT 'pending',
            ACCESS_TOKEN MEDIUMTEXT NULL,
            REFRESH_TOKEN MEDIUMTEXT NULL,
            EXPIRES_AT INT UNSIGNED NOT NULL DEFAULT 0,
            EXTERNAL_PARTY VARCHAR(128) NOT NULL DEFAULT '',
            CONSENT_ID VARCHAR(128) NOT NULL DEFAULT '',
            META MEDIUMTEXT NULL,
            LABEL VARCHAR(120) NOT NULL DEFAULT '',
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_bank_conn_provider (PROVIDER),
            KEY idx_bank_conn_status (STATUS)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_bank_account_links (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_CONNECTION INT UNSIGNED NOT NULL DEFAULT 0,
            EXTERNAL_ACCOUNT_ID VARCHAR(128) NOT NULL DEFAULT '',
            ACCOUNT_NUMBER VARCHAR(64) NOT NULL DEFAULT '',
            CURRENCY VARCHAR(8) NOT NULL DEFAULT 'RUB',
            REMOTE_NAME VARCHAR(255) NOT NULL DEFAULT '',
            ID_ACCOUNT INT UNSIGNED NOT NULL DEFAULT 0,
            SYNC_ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            LAST_SYNC_AT INT UNSIGNED NOT NULL DEFAULT 0,
            CURSOR_VAL VARCHAR(128) NOT NULL DEFAULT '',
            META MEDIUMTEXT NULL,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_bank_link_conn_ext (ID_CONNECTION, EXTERNAL_ACCOUNT_ID),
            KEY idx_bank_link_account (ID_ACCOUNT),
            KEY idx_bank_link_sync (SYNC_ENABLED, ID_ACCOUNT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_bank_tx_map (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            PROVIDER VARCHAR(32) NOT NULL DEFAULT '',
            EXTERNAL_TX_ID VARCHAR(191) NOT NULL DEFAULT '',
            ID_MOVE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_LINK INT UNSIGNED NOT NULL DEFAULT 0,
            ID_CONNECTION INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_bank_tx (PROVIDER, EXTERNAL_TX_ID),
            KEY idx_bank_tx_move (ID_MOVE),
            KEY idx_bank_tx_link (ID_LINK)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
