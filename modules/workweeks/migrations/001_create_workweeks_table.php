<?php
declare(strict_types=1);

/**
 * Staff day assignments (shifts). Shared with legacy oper / analytics.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_workweeks (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_OPERATOR INT UNSIGNED NOT NULL DEFAULT 0,
            ID_OPERATOR_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            PRICE_COL INT UNSIGNED NOT NULL DEFAULT 1,
            ID_TABLE INT UNSIGNED NOT NULL DEFAULT 0,
            MAS_MAX INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (ID),
            KEY idx_dt (DT),
            KEY idx_operator_dt (ID_OPERATOR, DT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $adds = [
        'PRICE_COL' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER ID_OPERATOR_CREATE',
        'ID_TABLE' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER PRICE_COL',
        'MAS_MAX' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER ID_TABLE',
    ];
    foreach ($adds as $col => $def) {
        if (!Database::columnExists('base_workweeks', $col)) {
            $pdo->exec('ALTER TABLE `base_workweeks` ADD COLUMN `' . $col . '` ' . $def);
        }
    }
};
