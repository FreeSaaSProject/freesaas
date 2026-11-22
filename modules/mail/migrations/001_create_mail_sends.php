<?php
declare(strict_types=1);

/**
 * Outbound email queue (mail-owned). STATUS: 1 pending, 2 sent, 3 error, 100 skipped.
 * DTCREATE / DTSEND are unix timestamps (DTSEND = scheduled time while pending, actual time once sent).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `base_mail_sends` (
            `ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `DTCREATE` INT UNSIGNED NOT NULL DEFAULT 0,
            `DTSEND` INT UNSIGNED NOT NULL DEFAULT 0,
            `STATUS` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `TO_ADDR` VARCHAR(255) NOT NULL DEFAULT '',
            `TO_NAME` VARCHAR(255) NULL DEFAULT NULL,
            `SUBJECT` VARCHAR(500) NOT NULL DEFAULT '',
            `BODY_TEXT` MEDIUMTEXT NULL,
            `BODY_HTML` MEDIUMTEXT NULL,
            `FROM_ADDR` VARCHAR(255) NULL DEFAULT NULL,
            `REPLY_TO` VARCHAR(255) NULL DEFAULT NULL,
            `ERROR` VARCHAR(512) NULL DEFAULT NULL,
            `STATUS_DESCR` VARCHAR(255) NULL DEFAULT NULL,
            `DEDUPE_KEY` VARCHAR(64) NULL DEFAULT NULL,
            `ID_OPERATOR` INT UNSIGNED NOT NULL DEFAULT 0,
            `CLIENT_ID` INT UNSIGNED NOT NULL DEFAULT 0,
            `META_JSON` TEXT NULL,
            PRIMARY KEY (`ID`),
            INDEX (`STATUS`, `DTSEND`),
            INDEX (`TO_ADDR`),
            INDEX (`DEDUPE_KEY`),
            INDEX (`CLIENT_ID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    Database::clearSchemaCache();
};
