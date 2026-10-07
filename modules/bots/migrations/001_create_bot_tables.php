<?php
declare(strict_types=1);

/**
 * Outbound queue (oper-compatible base_telegram_sends).
 * Messenger identities live in base_bot_links (migration 002) — not on base_users/base_clients.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `base_telegram_sends` (
            `ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ID_USER` INT UNSIGNED NOT NULL DEFAULT 0,
            `ID_OPERATOR` INT UNSIGNED NOT NULL DEFAULT 0,
            `TYPE` INT UNSIGNED NOT NULL DEFAULT 11,
            `STATUS` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `DTCREATE` INT UNSIGNED NOT NULL DEFAULT 0,
            `DTSEND` INT UNSIGNED NOT NULL DEFAULT 0,
            `CHAT_ID` VARCHAR(64) NULL DEFAULT NULL,
            `NAME` VARCHAR(255) NULL DEFAULT NULL,
            `ID_DATA` INT UNSIGNED NULL DEFAULT NULL,
            `TEXT` TEXT NULL,
            `ID_EVENT` INT UNSIGNED NULL DEFAULT NULL,
            `ID_POLL` INT UNSIGNED NULL DEFAULT NULL,
            `ERROR` VARCHAR(512) NULL DEFAULT NULL,
            `STATUS_DESCR` VARCHAR(255) NULL DEFAULT NULL,
            `BOT_SOURCE` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `DT_SEND_DEADLINE` INT UNSIGNED NULL DEFAULT NULL,
            `DEDUPE_KEY` VARCHAR(64) NULL DEFAULT NULL,
            PRIMARY KEY (`ID`),
            INDEX (`STATUS`),
            INDEX (`DTSEND`),
            INDEX (`ID_USER`),
            INDEX (`CHAT_ID`),
            INDEX (`BOT_SOURCE`),
            INDEX (`DEDUPE_KEY`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (Database::tableExists('base_telegram_sends')) {
        $adds = [
            'BOT_SOURCE' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
            'DT_SEND_DEADLINE' => 'INT UNSIGNED NULL DEFAULT NULL',
            'DEDUPE_KEY' => 'VARCHAR(64) NULL DEFAULT NULL',
        ];
        foreach ($adds as $col => $def) {
            if (!Database::columnExists('base_telegram_sends', $col)) {
                $pdo->exec('ALTER TABLE `base_telegram_sends` ADD COLUMN `' . $col . '` ' . $def);
                Database::clearSchemaCache();
            }
        }
    }
};
