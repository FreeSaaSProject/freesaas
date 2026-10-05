<?php
declare(strict_types=1);

/**
 * Pipeline result tables + stem cache. Shared with oper.
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
        "CREATE TABLE IF NOT EXISTS base_records_instances (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_START INT UNSIGNED NOT NULL DEFAULT 0,
            DT_END INT UNSIGNED NOT NULL DEFAULT 0,
            STRING_NUM INT UNSIGNED NOT NULL DEFAULT 0,
            STRING_POSITION INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
            ID_RECORD INT UNSIGNED NOT NULL DEFAULT 0,
            ID_KEY INT UNSIGNED NOT NULL DEFAULT 0,
            LENGTH INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY ID_RECORD (ID_RECORD),
            KEY ID_KEY (ID_KEY),
            KEY DT_START (DT_START)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ensureCol($pdo, 'base_records_instances', 'LENGTH', 'LENGTH INT UNSIGNED NOT NULL DEFAULT 0');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_collections (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_START INT UNSIGNED NOT NULL DEFAULT 0,
            DT_END INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
            ID_SCRIPT INT UNSIGNED NOT NULL DEFAULT 0,
            SCORE TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY ID_ADMIN (ID_ADMIN),
            KEY ID_SCRIPT (ID_SCRIPT),
            KEY DT_START (DT_START)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Legacy column was LEVEL, renamed to SCORE in patch 441
    $ensureCol($pdo, 'base_records_collections', 'SCORE', 'SCORE TINYINT UNSIGNED NOT NULL DEFAULT 0');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_collections_datas (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_START INT UNSIGNED NOT NULL DEFAULT 0,
            ID_COLLECTION INT UNSIGNED NOT NULL DEFAULT 0,
            ID_PART INT UNSIGNED NOT NULL DEFAULT 0,
            ID_INSTANCE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_KEY INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY ID_COLLECTION (ID_COLLECTION)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_stem_word_cache (
            WORD_HASH CHAR(32) NOT NULL,
            STEM_VERSION VARCHAR(32) NOT NULL,
            LEMMA VARCHAR(255) NOT NULL DEFAULT '',
            NO_STEM TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATED INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (WORD_HASH, STEM_VERSION)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_stem_key_cache (
            KEY_ID INT UNSIGNED NOT NULL,
            NAME_HASH CHAR(32) NOT NULL DEFAULT '',
            STEM_VERSION VARCHAR(32) NOT NULL DEFAULT '',
            PAYLOAD MEDIUMTEXT NULL,
            DT_UPDATED INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (KEY_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
