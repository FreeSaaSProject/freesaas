<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    if (!Database::tableExists('base_media_attachments')) {
        $pdo->exec(
            'CREATE TABLE `base_media_attachments` (
                `ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `DT_CREATE` INT UNSIGNED NOT NULL DEFAULT 0,
                `ID_AUTHOR` INT UNSIGNED NOT NULL DEFAULT 0,
                `OWNER_TABLE` VARCHAR(64) NOT NULL DEFAULT \'\',
                `OWNER_ID` INT UNSIGNED NOT NULL DEFAULT 0,
                `KIND` VARCHAR(32) NOT NULL DEFAULT \'other\',
                `STORAGE_PATH` VARCHAR(512) NOT NULL DEFAULT \'\',
                `THUMB_PATH` VARCHAR(512) NOT NULL DEFAULT \'\',
                `MIME` VARCHAR(64) NOT NULL DEFAULT \'\',
                `BYTES` INT UNSIGNED NOT NULL DEFAULT 0,
                `WIDTH` INT UNSIGNED NOT NULL DEFAULT 0,
                `HEIGHT` INT UNSIGNED NOT NULL DEFAULT 0,
                `ORIGINAL_NAME` VARCHAR(255) NOT NULL DEFAULT \'\',
                `META_JSON` TEXT NULL,
                PRIMARY KEY (`ID`),
                KEY `idx_owner` (`OWNER_TABLE`, `OWNER_ID`),
                KEY `idx_owner_kind` (`OWNER_TABLE`, `OWNER_ID`, `KIND`),
                KEY `idx_dt` (`DT_CREATE`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    if (!Database::tableExists('base_media_requirements')) {
        $pdo->exec(
            'CREATE TABLE `base_media_requirements` (
                `ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `SCOPE_TABLE` VARCHAR(64) NOT NULL DEFAULT \'\',
                `SCOPE_ID` INT UNSIGNED NOT NULL DEFAULT 0,
                `KIND` VARCHAR(32) NOT NULL DEFAULT \'other\',
                `MIN_COUNT` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `LABEL` VARCHAR(128) NOT NULL DEFAULT \'\',
                `ENABLED` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (`ID`),
                KEY `idx_scope` (`SCOPE_TABLE`, `SCOPE_ID`),
                UNIQUE KEY `uq_scope_kind` (`SCOPE_TABLE`, `SCOPE_ID`, `KIND`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
