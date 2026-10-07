<?php
declare(strict_types=1);

/**
 * OAuth2 clients / codes / tokens for MCP & external GPT Actions.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS base_oauth_clients (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            CLIENT_ID VARCHAR(64) NOT NULL,
            CLIENT_SECRET_HASH VARCHAR(128) NOT NULL DEFAULT \'\',
            NAME VARCHAR(191) NOT NULL DEFAULT \'\',
            REDIRECT_URIS MEDIUMTEXT NULL,
            SCOPES VARCHAR(255) NOT NULL DEFAULT \'ops.read\',
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            CREATED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            CREATED_BY INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_oauth_clients_cid (CLIENT_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS base_oauth_codes (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            CODE_HASH VARCHAR(128) NOT NULL,
            CLIENT_ID VARCHAR(64) NOT NULL,
            USER_ID INT UNSIGNED NOT NULL,
            REDIRECT_URI VARCHAR(512) NOT NULL DEFAULT \'\',
            SCOPE VARCHAR(255) NOT NULL DEFAULT \'\',
            CODE_CHALLENGE VARCHAR(128) NOT NULL DEFAULT \'\',
            CODE_CHALLENGE_METHOD VARCHAR(16) NOT NULL DEFAULT \'\',
            EXPIRES_AT INT UNSIGNED NOT NULL DEFAULT 0,
            USED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_oauth_codes_hash (CODE_HASH),
            KEY idx_oauth_codes_client (CLIENT_ID),
            KEY idx_oauth_codes_exp (EXPIRES_AT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS base_oauth_tokens (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ACCESS_TOKEN_HASH VARCHAR(128) NOT NULL,
            REFRESH_TOKEN_HASH VARCHAR(128) NOT NULL DEFAULT \'\',
            CLIENT_ID VARCHAR(64) NOT NULL,
            USER_ID INT UNSIGNED NOT NULL,
            SCOPE VARCHAR(255) NOT NULL DEFAULT \'\',
            ACCESS_EXPIRES_AT INT UNSIGNED NOT NULL DEFAULT 0,
            REFRESH_EXPIRES_AT INT UNSIGNED NOT NULL DEFAULT 0,
            REVOKED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            CREATED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_oauth_access (ACCESS_TOKEN_HASH),
            KEY idx_oauth_refresh (REFRESH_TOKEN_HASH),
            KEY idx_oauth_tokens_user (USER_ID),
            KEY idx_oauth_tokens_client (CLIENT_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
};
