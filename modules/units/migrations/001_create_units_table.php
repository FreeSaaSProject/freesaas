<?php
declare(strict_types=1);

/**
 * Units of measure. Shared with legacy oper — keep uppercase column names.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_units (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            NAME VARCHAR(10) NOT NULL DEFAULT '',
            WEIGHT_REQ TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
