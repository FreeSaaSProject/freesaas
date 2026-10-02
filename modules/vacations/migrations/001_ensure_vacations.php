<?php
declare(strict_types=1);

/**
 * Vacation register. Shared with legacy oper — keep uppercase column names.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_vacations (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_OPERATOR INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER_SIGN INT UNSIGNED NOT NULL DEFAULT 0,
            SIGN_REQ TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_START INT UNSIGNED NOT NULL DEFAULT 0,
            DT_END INT UNSIGNED NOT NULL DEFAULT 0,
            DESCR VARCHAR(255) NOT NULL DEFAULT '',
            DAYS_LONG INT UNSIGNED NOT NULL DEFAULT 0,
            DAYS_PAID_AMOUNT INT UNSIGNED NOT NULL DEFAULT 0,
            DAYS_UNPAID_AMOUNT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_TYPE TINYINT UNSIGNED NOT NULL DEFAULT 3,
            DIRECTION TINYINT NOT NULL DEFAULT 0,
            SHOW_USER TINYINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (ID),
            KEY idx_vacations_user (ID_USER),
            KEY idx_vacations_dates (DT_START, DT_END)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
