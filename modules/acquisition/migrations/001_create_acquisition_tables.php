<?php
declare(strict_types=1);

/**
 * Marketing acquisition: channel dictionary + inquiry/call journal.
 * Additive columns on base_clients / base_account_moves are not owned (no DROP).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_promo_sources (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            NAME VARCHAR(100) NOT NULL DEFAULT '',
            CONTACTS TEXT NULL,
            DESCRIPTION TEXT NULL,
            RESULT TEXT NULL,
            DT_START INT UNSIGNED NOT NULL DEFAULT 0,
            DT_END INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            SRC_ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (ID),
            KEY idx_promo_src_enabled (ENABLED, SRC_ENABLED),
            KEY idx_promo_src_name (NAME)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_calls (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
            ID_CLIENT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_CLIENT_TYPE TINYINT UNSIGNED NOT NULL DEFAULT 0,
            ID_RESULT TINYINT UNSIGNED NOT NULL DEFAULT 0,
            DT_CALL INT UNSIGNED NOT NULL DEFAULT 0,
            DT_FILLED INT UNSIGNED NOT NULL DEFAULT 0,
            PHONE VARCHAR(16) NOT NULL DEFAULT '',
            TYPE TINYINT UNSIGNED NOT NULL DEFAULT 1,
            CHANNEL VARCHAR(32) NOT NULL DEFAULT 'manual',
            PROVIDER VARCHAR(32) NOT NULL DEFAULT '',
            PROVIDER_CALL_ID VARCHAR(64) NOT NULL DEFAULT '',
            REQUEST TEXT NULL,
            RESULT_TEXT TEXT NULL,
            FILEDATA TEXT NULL,
            ID_EVENT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ORDER INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_calls_dt (DT_CALL),
            KEY idx_calls_phone (PHONE),
            KEY idx_calls_client (ID_CLIENT),
            KEY idx_calls_result (ID_RESULT, DT_FILLED),
            KEY idx_calls_provider (PROVIDER, PROVIDER_CALL_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensureCol = static function (PDO $pdo, string $table, string $col, string $ddl): void {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$table, $col]);
        if ((int)$st->fetchColumn() < 1) {
            $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN ' . $ddl);
        }
    };

    $stTbl = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_clients'"
    );
    if ($stTbl && (int)$stTbl->fetchColumn() > 0) {
        $ensureCol($pdo, 'base_clients', 'source_id', 'source_id INT UNSIGNED NOT NULL DEFAULT 0');
        $ensureCol($pdo, 'base_clients', 'source_descr', "source_descr VARCHAR(255) NOT NULL DEFAULT ''");
        $ensureCol($pdo, 'base_clients', 'referred_by_client_id', 'referred_by_client_id INT UNSIGNED NOT NULL DEFAULT 0');
    }

    $stMoves = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_account_moves'"
    );
    if ($stMoves && (int)$stMoves->fetchColumn() > 0) {
        $ensureCol($pdo, 'base_account_moves', 'ID_SOURCE', 'ID_SOURCE INT UNSIGNED NOT NULL DEFAULT 0');
    }
};
