<?php
declare(strict_types=1);

/**
 * Client MCP sessions: hashed client_token → phone/client + orders booked in that session.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_client_mcp_sessions (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            TOKEN_HASH CHAR(64) NOT NULL,
            PHONE VARCHAR(20) NOT NULL DEFAULT '',
            ID_CLIENT INT UNSIGNED NOT NULL DEFAULT 0,
            CLIENT_NAME VARCHAR(255) NOT NULL DEFAULT '',
            KNOWN_CLIENT TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            VERIFY_MODE VARCHAR(16) NOT NULL DEFAULT 'none',
            VERIFIED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            CONSENT_AT INT UNSIGNED NOT NULL DEFAULT 0,
            ORDER_IDS TEXT NOT NULL,
            BOOKINGS INT UNSIGNED NOT NULL DEFAULT 0,
            IP VARCHAR(45) NOT NULL DEFAULT '',
            USER_AGENT VARCHAR(255) NOT NULL DEFAULT '',
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_SEEN INT UNSIGNED NOT NULL DEFAULT 0,
            DT_EXPIRE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_token (TOKEN_HASH),
            KEY idx_ip_create (IP, DT_CREATE),
            KEY idx_phone_create (PHONE, DT_CREATE),
            KEY idx_expire (DT_EXPIRE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
