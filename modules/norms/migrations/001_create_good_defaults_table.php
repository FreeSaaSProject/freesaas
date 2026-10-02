<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_good_defaults (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_PROC INT UNSIGNED NOT NULL DEFAULT 0,
            ID_GOOD INT UNSIGNED NOT NULL DEFAULT 0,
            QTYU INT NOT NULL DEFAULT 0,
            QTYU2 FLOAT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_proc (ID_PROC),
            KEY idx_good (ID_GOOD)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (!Database::columnExists('base_good_defaults', 'QTYU2')) {
        $pdo->exec('ALTER TABLE `base_good_defaults` ADD COLUMN `QTYU2` FLOAT UNSIGNED NOT NULL DEFAULT 0 AFTER `QTYU`');
    }
};
