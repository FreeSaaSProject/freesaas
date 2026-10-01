<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    if (!Database::tableExists('base_users')) {
        return;
    }
    if (Database::columnExists('base_users', 'locale')) {
        return;
    }
    $pdo->exec("ALTER TABLE `base_users` ADD COLUMN `locale` VARCHAR(8) NULL DEFAULT NULL");
};
