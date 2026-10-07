<?php
declare(strict_types=1);

/**
 * Pending write-tool confirmations for the in-system assistant harness.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS base_assistant_pending (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            TOKEN VARCHAR(32) NOT NULL,
            ID_USER INT UNSIGNED NOT NULL,
            OP_ID VARCHAR(128) NOT NULL,
            ARGS_JSON MEDIUMTEXT NULL,
            SUMMARY VARCHAR(512) NOT NULL DEFAULT \'\',
            STATUS VARCHAR(16) NOT NULL DEFAULT \'pending\',
            RESULT_JSON MEDIUMTEXT NULL,
            CREATED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            EXPIRES_AT INT UNSIGNED NOT NULL DEFAULT 0,
            RESOLVED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_assistant_pending_token (TOKEN),
            KEY idx_assistant_pending_user (ID_USER),
            KEY idx_assistant_pending_status (STATUS, EXPIRES_AT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
};
