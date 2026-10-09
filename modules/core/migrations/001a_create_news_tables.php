<?php
declare(strict_types=1);

/**
 * Greenfield news/tickets schema (base_news, statuses, views).
 * Must run before 002_notice_indexes. Idempotent for existing oper-derived tables.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_news (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            SHORT VARCHAR(255) NOT NULL DEFAULT '',
            BIG LONGTEXT NOT NULL,
            ACCESS_GROUP VARCHAR(255) NOT NULL DEFAULT '',
            ACCESS_GROUP2 VARCHAR(255) NOT NULL DEFAULT '',
            ACCESS_USER VARCHAR(255) NOT NULL DEFAULT '',
            TYPE TINYINT NOT NULL DEFAULT 0,
            DISABLED TINYINT(1) NOT NULL DEFAULT 0,
            STATUS TINYINT NOT NULL DEFAULT 0,
            USERS_WATCHED MEDIUMTEXT NULL,
            SIGN_REQ TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            USERS_SIGNED MEDIUMTEXT NULL,
            COMMENTS_LOCKED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            DT_DEADLINE INT UNSIGNED NOT NULL DEFAULT 0,
            USERS_RESP VARCHAR(255) NOT NULL DEFAULT '',
            DT_STARTLINE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_CURATOR INT UNSIGNED NOT NULL DEFAULT 0,
            PID INT UNSIGNED NOT NULL DEFAULT 0,
            HAS_CHILDREN TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            ID_USER_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PID_CONTRACT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_OFFER_COMMENT INT UNSIGNED NOT NULL DEFAULT 0,
            IGNORE_FOLDER TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_news_feed (DISABLED, HAS_CHILDREN, TYPE, STATUS, DT_DEADLINE),
            FULLTEXT KEY ft_news_big (BIG)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_news_statuses (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            NAME VARCHAR(50) NOT NULL DEFAULT '',
            TYPE TINYINT NOT NULL DEFAULT 0,
            DEADLINE_POLICY VARCHAR(16) NOT NULL DEFAULT 'optional',
            COLOR VARCHAR(10) NOT NULL DEFAULT '',
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_views (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            ID_OBJ INT UNSIGNED NOT NULL DEFAULT 0,
            TYPE_OBJ VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (ID),
            KEY idx_views_lookup (TYPE_OBJ(191), ID_USER, ID_OBJ)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    Database::clearSchemaCache();
};
