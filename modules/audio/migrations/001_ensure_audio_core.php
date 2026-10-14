<?php
declare(strict_types=1);

/**
 * Core audio tables. Shared with oper — CREATE IF NOT EXISTS only; never repartition base_records.
 */
return static function (PDO $pdo): void {
    $ensureCol = static function (PDO $pdo, string $table, string $col, string $ddl): void {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$table, $col]);
        if ((int)$st->fetchColumn() < 1) {
            $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN ' . $ddl);
        }
    };

    // Storage/FTP settings live in cfg/modules/audio.ini ([storage] / [audio]), not a KV table.

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_badges (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            CODE VARCHAR(20) NOT NULL,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            DT_FIRST_SEEN INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_badge_code (CODE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_badge_assignments (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_OPERATOR INT UNSIGNED NOT NULL DEFAULT 0,
            ID_BADGE VARCHAR(20) NOT NULL DEFAULT '',
            DT_START INT UNSIGNED NOT NULL DEFAULT 0,
            DT_END INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_CREATOR INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY ID_OPERATOR (ID_OPERATOR),
            KEY ID_BADGE (ID_BADGE),
            KEY DT_START (DT_START),
            KEY DT_END (DT_END)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Fresh install only — if table already exists (shared/partitioned), leave PK alone.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
            ID_CLIENT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_DICTOPHONE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_RECORDED INT UNSIGNED NOT NULL DEFAULT 0,
            DT_TRANSCRIBED INT UNSIGNED NOT NULL DEFAULT 0,
            DT_PARSED INT UNSIGNED NOT NULL DEFAULT 0,
            DT_BACKUPED INT UNSIGNED NOT NULL DEFAULT 0,
            FILENAME VARCHAR(255) NOT NULL DEFAULT '',
            TRANSCRIPTION MEDIUMTEXT NULL,
            DESCRIPTION TEXT NULL,
            PLAYTIME INT UNSIGNED NOT NULL DEFAULT 0,
            OFFERS INT UNSIGNED NOT NULL DEFAULT 0,
            VOLUME FLOAT NOT NULL DEFAULT 0,
            QUALITY TINYINT UNSIGNED NOT NULL DEFAULT 0,
            DISABLED TINYINT UNSIGNED NOT NULL DEFAULT 0,
            INTERSECTIONS TEXT NULL,
            REPLICATECOM_ID VARCHAR(200) NOT NULL DEFAULT '',
            KEY_TYPES VARCHAR(255) NOT NULL DEFAULT '',
            SCORE INT UNSIGNED NOT NULL DEFAULT 0,
            STRINGS INT UNSIGNED NOT NULL DEFAULT 0,
            STRINGS_NOK INT UNSIGNED NOT NULL DEFAULT 0,
            ERROR_TRANS TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY DT_RECORDED (DT_RECORDED),
            KEY idx_admin_date (ID_ADMIN, DT_RECORDED),
            KEY idx_dictophone_date (ID_DICTOPHONE, DT_RECORDED)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    foreach ([
        'DT_PARSED' => 'DT_PARSED INT UNSIGNED NOT NULL DEFAULT 0',
        'DT_BACKUPED' => 'DT_BACKUPED INT UNSIGNED NOT NULL DEFAULT 0',
        'PLAYTIME' => 'PLAYTIME INT UNSIGNED NOT NULL DEFAULT 0',
        'OFFERS' => 'OFFERS INT UNSIGNED NOT NULL DEFAULT 0',
        'VOLUME' => 'VOLUME FLOAT NOT NULL DEFAULT 0',
        'QUALITY' => 'QUALITY TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'DISABLED' => 'DISABLED TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'INTERSECTIONS' => 'INTERSECTIONS TEXT NULL',
        'REPLICATECOM_ID' => 'REPLICATECOM_ID VARCHAR(200) NOT NULL DEFAULT \'\'',
        'KEY_TYPES' => 'KEY_TYPES VARCHAR(255) NOT NULL DEFAULT \'\'',
        'SCORE' => 'SCORE INT UNSIGNED NOT NULL DEFAULT 0',
        'STRINGS' => 'STRINGS INT UNSIGNED NOT NULL DEFAULT 0',
        'STRINGS_NOK' => 'STRINGS_NOK INT UNSIGNED NOT NULL DEFAULT 0',
        'ERROR_TRANS' => 'ERROR_TRANS TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
    ] as $col => $ddl) {
        $ensureCol($pdo, 'base_records', $col, $ddl);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_balances (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            DIRECTION TINYINT(1) NOT NULL DEFAULT 0,
            AMOUNT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_RECORD INT UNSIGNED NOT NULL DEFAULT 0,
            DELETED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY ID_RECORD (ID_RECORD)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ensureCol($pdo, 'base_records_balances', 'DELETED', 'DELETED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_reviews (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_RECORD INT UNSIGNED NOT NULL DEFAULT 0,
            ID_REVIEWER INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_REVIEW INT UNSIGNED NOT NULL DEFAULT 0,
            DT_SELECT INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_review_record (ID_RECORD),
            KEY idx_review_record_dt (ID_RECORD, DT_REVIEW)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ensureCol($pdo, 'base_records_reviews', 'DT_SELECT', 'DT_SELECT INT UNSIGNED NOT NULL DEFAULT 0');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_telemetrys (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_TERMINAL INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
            STEPS INT UNSIGNED NOT NULL DEFAULT 0,
            DT_LASTSTEP INT UNSIGNED NOT NULL DEFAULT 0,
            ACCEL FLOAT NOT NULL DEFAULT 0,
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
