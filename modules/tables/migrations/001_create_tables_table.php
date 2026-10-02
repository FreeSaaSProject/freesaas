<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_tables (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_OPERATOR_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            PROC_TYPES MEDIUMTEXT NOT NULL,
            PROCS MEDIUMTEXT NOT NULL,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            ENABLED TINYINT UNSIGNED NOT NULL DEFAULT 1,
            GENDER VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $adds = [
        'DT_CREATE' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER ID',
        'ID_OPERATOR_CREATE' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER DT_CREATE',
        'PROC_TYPES' => 'MEDIUMTEXT NOT NULL AFTER ID_OPERATOR_CREATE',
        'PROCS' => 'MEDIUMTEXT NOT NULL AFTER PROC_TYPES',
        'NAME' => "VARCHAR(255) NOT NULL DEFAULT '' AFTER PROCS",
        'ENABLED' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER NAME',
        'GENDER' => "VARCHAR(255) NOT NULL DEFAULT '' AFTER ENABLED",
    ];
    foreach ($adds as $col => $def) {
        if (!Database::columnExists('base_tables', $col)) {
            $pdo->exec('ALTER TABLE `base_tables` ADD COLUMN `' . $col . '` ' . $def);
        }
    }
};
