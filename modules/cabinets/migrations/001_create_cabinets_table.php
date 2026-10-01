<?php
declare(strict_types=1);

/**
 * Rooms / cabinets dictionary. Shared with legacy oper.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_cabinets (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            DESCR VARCHAR(255) NOT NULL DEFAULT '',
            LIMIT_MIN INT NOT NULL DEFAULT 0,
            LIMIT_MAX INT NOT NULL DEFAULT 0,
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $adds = [
        'LIMIT_MIN' => 'INT NOT NULL DEFAULT 0 AFTER DESCR',
        'LIMIT_MAX' => 'INT NOT NULL DEFAULT 0 AFTER LIMIT_MIN',
    ];
    foreach ($adds as $col => $def) {
        if (!Database::columnExists('base_cabinets', $col)) {
            $pdo->exec('ALTER TABLE `base_cabinets` ADD COLUMN `' . $col . '` ' . $def);
        }
    }
};
