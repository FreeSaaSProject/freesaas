<?php
declare(strict_types=1);

/**
 * Per-chat FSM state for staff/client bot plugins (pending mode, JSON payload).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `base_bot_chat_state` (
            `ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `BOT_SOURCE` TINYINT UNSIGNED NOT NULL DEFAULT 2,
            `PLATFORM` VARCHAR(16) NOT NULL DEFAULT 'telegram',
            `CHAT_ID` VARCHAR(64) NOT NULL DEFAULT '',
            `MODULE_ID` VARCHAR(64) NOT NULL DEFAULT '',
            `MODE` VARCHAR(64) NOT NULL DEFAULT '',
            `DATA_JSON` MEDIUMTEXT NULL,
            `DTUPDATE` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`ID`),
            UNIQUE KEY `uq_bot_chat_state` (`BOT_SOURCE`, `PLATFORM`, `CHAT_ID`),
            KEY `idx_bot_chat_state_module` (`MODULE_ID`, `MODE`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
