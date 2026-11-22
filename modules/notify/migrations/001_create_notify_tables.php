<?php
declare(strict_types=1);

/**
 * Notify rules, delivery journal and confirmation tasks (notify-owned).
 * DTCREATE / DTUPDATE / *_TS are unix timestamps.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `base_notify_rules` (
            `ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `NAME` VARCHAR(255) NOT NULL DEFAULT '',
            `EVENT` VARCHAR(64) NOT NULL DEFAULT '',
            `ENABLED` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `SORT` INT NOT NULL DEFAULT 100,
            `STOP_ON_MATCH` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `KIND` VARCHAR(16) NOT NULL DEFAULT 'notify',
            `CONDITIONS_JSON` TEXT NULL,
            `TARGETS_JSON` TEXT NULL,
            `TEMPLATE` TEXT NULL,
            `STAGES_JSON` TEXT NULL,
            `QUIET_FROM` VARCHAR(5) NULL DEFAULT NULL,
            `QUIET_TO` VARCHAR(5) NULL DEFAULT NULL,
            `DTCREATE` INT UNSIGNED NOT NULL DEFAULT 0,
            `DTUPDATE` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`ID`),
            INDEX (`EVENT`, `ENABLED`, `SORT`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `base_notify_deliveries` (
            `ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `DTCREATE` INT UNSIGNED NOT NULL DEFAULT 0,
            `RULE_ID` INT UNSIGNED NOT NULL DEFAULT 0,
            `EVENT` VARCHAR(64) NOT NULL DEFAULT '',
            `ENTITY` VARCHAR(32) NOT NULL DEFAULT '',
            `ENTITY_ID` INT UNSIGNED NOT NULL DEFAULT 0,
            `CLIENT_ID` INT UNSIGNED NOT NULL DEFAULT 0,
            `RCPT_KIND` VARCHAR(16) NOT NULL DEFAULT '',
            `RCPT_ID` VARCHAR(64) NOT NULL DEFAULT '',
            `CHANNEL` VARCHAR(16) NOT NULL DEFAULT '',
            `ADDRESS` VARCHAR(255) NOT NULL DEFAULT '',
            `SUBJECT` VARCHAR(255) NOT NULL DEFAULT '',
            `TEXT` MEDIUMTEXT NULL,
            `STATUS` VARCHAR(16) NOT NULL DEFAULT 'queued',
            `QUEUE_ID` INT UNSIGNED NOT NULL DEFAULT 0,
            `QUEUE_KIND` VARCHAR(16) NOT NULL DEFAULT '',
            `PLATFORM_MSG_ID` VARCHAR(128) NOT NULL DEFAULT '',
            `ERROR` VARCHAR(512) NOT NULL DEFAULT '',
            `DEDUPE_KEY` VARCHAR(191) NOT NULL DEFAULT '',
            `META_JSON` TEXT NULL,
            `TASK_ID` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`ID`),
            INDEX (`CLIENT_ID`, `DTCREATE`),
            INDEX (`RCPT_KIND`, `RCPT_ID`, `DTCREATE`),
            INDEX (`ENTITY`, `ENTITY_ID`),
            INDEX (`DEDUPE_KEY`),
            INDEX (`STATUS`, `DTCREATE`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `base_notify_tasks` (
            `ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `RULE_ID` INT UNSIGNED NOT NULL DEFAULT 0,
            `EVENT` VARCHAR(64) NOT NULL DEFAULT '',
            `ENTITY` VARCHAR(32) NOT NULL DEFAULT '',
            `ENTITY_ID` INT UNSIGNED NOT NULL DEFAULT 0,
            `CLIENT_ID` INT UNSIGNED NOT NULL DEFAULT 0,
            `STAGE_KEY` VARCHAR(64) NOT NULL DEFAULT '',
            `ANCHOR_TS` INT UNSIGNED NOT NULL DEFAULT 0,
            `DUE_TS` INT UNSIGNED NOT NULL DEFAULT 0,
            `STATUS` VARCHAR(16) NOT NULL DEFAULT 'pending',
            `OUTCOME` VARCHAR(32) NOT NULL DEFAULT '',
            `RESOLVED_BY` INT UNSIGNED NOT NULL DEFAULT 0,
            `RESOLVED_AT` INT UNSIGNED NOT NULL DEFAULT 0,
            `RESOLVED_COMMENT` TEXT NULL,
            `ATTEMPTS` INT UNSIGNED NOT NULL DEFAULT 0,
            `LAST_DELIVERY_ID` INT UNSIGNED NOT NULL DEFAULT 0,
            `PLATFORM` VARCHAR(16) NOT NULL DEFAULT '',
            `CHAT_ID` VARCHAR(64) NOT NULL DEFAULT '',
            `PLATFORM_MSG_ID` VARCHAR(128) NOT NULL DEFAULT '',
            `DEDUPE_KEY` VARCHAR(191) NOT NULL,
            `DTCREATE` INT UNSIGNED NOT NULL DEFAULT 0,
            `DTUPDATE` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`ID`),
            UNIQUE KEY `uq_dedupe` (`DEDUPE_KEY`),
            INDEX (`STATUS`, `DUE_TS`),
            INDEX (`ENTITY`, `ENTITY_ID`),
            INDEX (`CLIENT_ID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    Database::clearSchemaCache();
};
