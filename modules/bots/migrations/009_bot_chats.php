<?php
declare(strict_types=1);

/**
 * Registry of known messenger group chats (Max / Telegram) for notify targets.
 */
return static function (PDO $pdo): void {
    if (Database::tableExists('base_bot_chats')) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `base_bot_chats` (
            `ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `PLATFORM` VARCHAR(16) NOT NULL DEFAULT 'max',
            `CHAT_ID` VARCHAR(64) NOT NULL,
            `TITLE` VARCHAR(255) NOT NULL DEFAULT '',
            `PURPOSE` VARCHAR(64) NOT NULL DEFAULT '',
            `DESCRIPTION` VARCHAR(512) NOT NULL DEFAULT '',
            `ENABLED` TINYINT(1) NOT NULL DEFAULT 1,
            `DTCREATE` INT UNSIGNED NOT NULL DEFAULT 0,
            `DTUPDATE` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`ID`),
            UNIQUE KEY `uq_bot_chats_platform_chat` (`PLATFORM`, `CHAT_ID`),
            KEY `idx_bot_chats_enabled` (`ENABLED`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    Database::clearSchemaCache();
};
