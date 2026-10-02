<?php
declare(strict_types=1);

/**
 * Warehouses dictionary. Shared with legacy oper — keep uppercase columns.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_stocks (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            NAME VARCHAR(20) NOT NULL DEFAULT '',
            DESCR VARCHAR(255) NOT NULL DEFAULT '',
            OPERATIVE TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            ID_CABINET INT UNSIGNED NOT NULL DEFAULT 0,
            DT INT NOT NULL DEFAULT 0,
            TYPE TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            AUTOCONTROL_REQ TINYINT UNSIGNED NOT NULL DEFAULT 0,
            VRT_STOCK_ID INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $adds = [
        'OPERATIVE' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 AFTER DESCR',
        'ID_CABINET' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER OPERATIVE',
        'DT' => 'INT NOT NULL DEFAULT 0 AFTER ID_CABINET',
        'TYPE' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 AFTER DT',
        'AUTOCONTROL_REQ' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER TYPE',
        'VRT_STOCK_ID' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER AUTOCONTROL_REQ',
    ];
    foreach ($adds as $col => $def) {
        if (!Database::columnExists('base_stocks', $col)) {
            $pdo->exec('ALTER TABLE `base_stocks` ADD COLUMN `' . $col . '` ' . $def);
        }
    }
};
