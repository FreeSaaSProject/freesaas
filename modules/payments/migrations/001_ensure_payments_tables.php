<?php
declare(strict_types=1);

/**
 * Staff settlements and payment types. Shared with legacy oper.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_payment_types (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DESCR VARCHAR(255) NOT NULL DEFAULT '',
            NAME VARCHAR(5) NOT NULL DEFAULT '',
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            COLOR VARCHAR(10) NOT NULL DEFAULT '',
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_payments (
            ID INT NOT NULL AUTO_INCREMENT,
            ID_USER INT NOT NULL DEFAULT 0,
            DT INT NOT NULL DEFAULT 0,
            DESCR MEDIUMTEXT NULL,
            DIRECTION CHAR(2) NOT NULL DEFAULT '0',
            SUM DOUBLE NOT NULL DEFAULT 0,
            ID_USER_SIGNED INT UNSIGNED NOT NULL DEFAULT 0,
            ID_CONTROL_NOTICE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_TYPE TINYINT UNSIGNED NOT NULL DEFAULT 0,
            ACCOUNT TINYINT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN_SIGNED INT UNSIGNED NOT NULL DEFAULT 0,
            DT_ADMIN_SIGNED INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ACCEPT_USER_SIGNED INT UNSIGNED NOT NULL DEFAULT 0,
            DT_ACCEPT_USER_SIGNED INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN_INWORKED INT UNSIGNED NOT NULL DEFAULT 0,
            DT_ADMIN_INWORKED INT UNSIGNED NOT NULL DEFAULT 0,
            IS_PARTNER_PAYMENT TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            ID_MOVEMENT_LINKED INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER_TO INT UNSIGNED NOT NULL DEFAULT 0,
            ID_TYPE_TO INT UNSIGNED NOT NULL DEFAULT 0,
            ID_RATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_COMMENT INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_payments_user_dt (ID_USER, DT),
            KEY idx_payments_comment (ID_COMMENT)
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
    $ensureIdx = static function (PDO $pdo, string $table, string $idx, string $ddl): void {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $st->execute([$table, $idx]);
        if ((int)$st->fetchColumn() < 1) {
            $pdo->exec('ALTER TABLE `' . $table . '` ADD ' . $ddl);
        }
    };

    $ensureCol($pdo, 'base_payments', 'ID_COMMENT', 'ID_COMMENT INT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_payments', 'IS_PARTNER_PAYMENT', 'IS_PARTNER_PAYMENT TINYINT(1) UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_payments', 'ID_MOVEMENT_LINKED', 'ID_MOVEMENT_LINKED INT UNSIGNED NOT NULL DEFAULT 0');
    $ensureIdx($pdo, 'base_payments', 'idx_payments_comment', 'INDEX idx_payments_comment (ID_COMMENT)');
};
