<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_client_portal_resets (
            ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            CLIENT_ID INT UNSIGNED NOT NULL DEFAULT 0,
            TOKEN_HASH CHAR(64) NOT NULL DEFAULT '',
            CHANNEL VARCHAR(32) NOT NULL DEFAULT '',
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_EXPIRE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_USED INT UNSIGNED NOT NULL DEFAULT 0,
            IP VARCHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY (ID),
            UNIQUE KEY uq_cpr_token (TOKEN_HASH),
            KEY idx_cpr_client (CLIENT_ID),
            KEY idx_cpr_expire (DT_EXPIRE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
