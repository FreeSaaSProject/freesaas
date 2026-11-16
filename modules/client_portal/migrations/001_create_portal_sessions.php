<?php
declare(strict_types=1);

/**
 * Client portal session journal (optional audit; live session is PHP cookie).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_client_portal_sessions (
            ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            CLIENT_ID INT UNSIGNED NOT NULL DEFAULT 0,
            SESSION_KEY VARCHAR(64) NOT NULL DEFAULT '',
            IP VARCHAR(64) NOT NULL DEFAULT '',
            USER_AGENT VARCHAR(255) NOT NULL DEFAULT '',
            DT_LOGIN INT UNSIGNED NOT NULL DEFAULT 0,
            DT_LAST INT UNSIGNED NOT NULL DEFAULT 0,
            DT_LOGOUT INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_cps_client (CLIENT_ID),
            KEY idx_cps_key (SESSION_KEY),
            KEY idx_cps_last (DT_LAST)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
