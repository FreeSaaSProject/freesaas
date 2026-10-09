<?php
declare(strict_types=1);

/**
 * Comment threads (news/tickets). Must exist before contracts index migrations.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_comments (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_OBJECT INT UNSIGNED NOT NULL DEFAULT 0,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            TEXT MEDIUMTEXT NOT NULL,
            TABLE_NAME VARCHAR(255) NOT NULL DEFAULT '',
            DISABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PID INT UNSIGNED NOT NULL DEFAULT 0,
            COLOR VARCHAR(255) NOT NULL DEFAULT '',
            STATUS_UPDATE TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRICE_OFFER INT UNSIGNED NOT NULL DEFAULT 0,
            DT_OFFER_ACCEPT INT UNSIGNED NOT NULL DEFAULT 0,
            DT_WORK_ACCEPT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ACCEPTOR INT UNSIGNED NOT NULL DEFAULT 0,
            BUDGET_OFFER INT UNSIGNED NOT NULL DEFAULT 0,
            DT_DEADLINE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_PAYMENT INT UNSIGNED NOT NULL DEFAULT 0,
            DT_FAIL INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_comments_pid (PID),
            KEY idx_comments_table (TABLE_NAME(191)),
            KEY idx_comments_object_dt (ID_OBJECT, DT),
            FULLTEXT KEY ft_comments_text (TEXT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    Database::clearSchemaCache();
};
