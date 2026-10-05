<?php
declare(strict_types=1);

/**
 * Recognition dictionaries / scripts. Shared with oper.
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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_types (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(20) NOT NULL DEFAULT '',
            DESCR VARCHAR(255) NOT NULL DEFAULT '',
            COLOR VARCHAR(10) NOT NULL DEFAULT '',
            COLOR_BACKGROUND VARCHAR(10) NOT NULL DEFAULT '',
            ENABLED TINYINT UNSIGNED NOT NULL DEFAULT 1,
            KPI_ENABLED TINYINT UNSIGNED NOT NULL DEFAULT 0,
            SCORE_VALUE INT NOT NULL DEFAULT 0,
            SCORE_TYPE_ID INT UNSIGNED NOT NULL DEFAULT 0,
            SCORE_DIVERSITY INT UNSIGNED NOT NULL DEFAULT 0,
            SCORE_INSTANCES_MIN INT NOT NULL DEFAULT 0,
            SCORE_INSTANCES_MAX INT NOT NULL DEFAULT 0,
            VOLUME INT UNSIGNED NOT NULL DEFAULT 0,
            STRINGS_AMOUNT INT UNSIGNED NOT NULL DEFAULT 0,
            INTERSECT TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            IS_HIDDEN INT UNSIGNED NOT NULL DEFAULT 0,
            ID_POSITION TEXT NULL,
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    foreach ([
        'SCORE_VALUE' => 'SCORE_VALUE INT NOT NULL DEFAULT 0',
        'SCORE_TYPE_ID' => 'SCORE_TYPE_ID INT UNSIGNED NOT NULL DEFAULT 0',
        'SCORE_DIVERSITY' => 'SCORE_DIVERSITY INT UNSIGNED NOT NULL DEFAULT 0',
        'SCORE_INSTANCES_MIN' => 'SCORE_INSTANCES_MIN INT NOT NULL DEFAULT 0',
        'SCORE_INSTANCES_MAX' => 'SCORE_INSTANCES_MAX INT NOT NULL DEFAULT 0',
        'VOLUME' => 'VOLUME INT UNSIGNED NOT NULL DEFAULT 0',
        'STRINGS_AMOUNT' => 'STRINGS_AMOUNT INT UNSIGNED NOT NULL DEFAULT 0',
        'INTERSECT' => 'INTERSECT TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
        'IS_HIDDEN' => 'IS_HIDDEN INT UNSIGNED NOT NULL DEFAULT 0',
        'ID_POSITION' => 'ID_POSITION TEXT NULL',
    ] as $col => $ddl) {
        $ensureCol($pdo, 'base_records_types', $col, $ddl);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_dictonarys (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(50) NOT NULL DEFAULT '',
            DESCR VARCHAR(100) NOT NULL DEFAULT '',
            COLOR VARCHAR(10) NOT NULL DEFAULT '',
            COLOR_BACKGROUND VARCHAR(10) NOT NULL DEFAULT '',
            DISABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            IS_REVIEW_REQ TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            ID_POSITION TEXT NULL,
            TRACKING_CODE VARCHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY (ID),
            KEY TRACKING_CODE (TRACKING_CODE(32))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    foreach ([
        'IS_REVIEW_REQ' => 'IS_REVIEW_REQ TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
        'ID_POSITION' => 'ID_POSITION TEXT NULL',
        'TRACKING_CODE' => 'TRACKING_CODE VARCHAR(64) NOT NULL DEFAULT \'\'',
    ] as $col => $ddl) {
        $ensureCol($pdo, 'base_records_dictonarys', $col, $ddl);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_keys (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_TYPE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            DISABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            ID_DICTONARY INT UNSIGNED NOT NULL DEFAULT 0,
            ID_LOOKLIKE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY ID_DICTONARY (ID_DICTONARY),
            KEY ID_TYPE (ID_TYPE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    foreach ([
        'ID_TYPE' => 'ID_TYPE INT UNSIGNED NOT NULL DEFAULT 0',
        'ID_DICTONARY' => 'ID_DICTONARY INT UNSIGNED NOT NULL DEFAULT 0',
        'ID_LOOKLIKE' => 'ID_LOOKLIKE INT UNSIGNED NOT NULL DEFAULT 0',
    ] as $col => $ddl) {
        $ensureCol($pdo, 'base_records_keys', $col, $ddl);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_keys_tags (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(100) NOT NULL DEFAULT '',
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_keys_tags_links (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_KEY INT UNSIGNED NOT NULL DEFAULT 0,
            ID_TAG INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY ID_KEY (ID_KEY),
            KEY ID_TAG (ID_TAG)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_scripts (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            PID INT UNSIGNED NOT NULL DEFAULT 0,
            NEXT_QID INT UNSIGNED NOT NULL DEFAULT 0,
            EL_TYPE TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            NUM TINYINT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(40) NOT NULL DEFAULT '',
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DISABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            IS_REQ TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            FINISH TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            IDS_DICTONARYS TEXT NULL,
            SCORE INT NOT NULL DEFAULT 0,
            `BREAK` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            TIMEOUT INT UNSIGNED NOT NULL DEFAULT 0,
            DIVERSITY INT UNSIGNED NOT NULL DEFAULT 0,
            INSTANCES_MIN INT UNSIGNED NOT NULL DEFAULT 0,
            COLLECTION_KILLER TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            ID_POSITION TEXT NULL,
            PRIMARY KEY (ID),
            KEY PID (PID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    foreach ([
        'BREAK' => '`BREAK` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
        'TIMEOUT' => 'TIMEOUT INT UNSIGNED NOT NULL DEFAULT 0',
        'DIVERSITY' => 'DIVERSITY INT UNSIGNED NOT NULL DEFAULT 0',
        'INSTANCES_MIN' => 'INSTANCES_MIN INT UNSIGNED NOT NULL DEFAULT 0',
        'COLLECTION_KILLER' => 'COLLECTION_KILLER TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
        'ID_POSITION' => 'ID_POSITION TEXT NULL',
    ] as $col => $ddl) {
        $ensureCol($pdo, 'base_records_scripts', $col, $ddl);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_trashes (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            ENABLED TINYINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
