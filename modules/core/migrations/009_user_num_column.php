<?php
declare(strict_types=1);

/**
 * Ensure base_users.num (staff list order). Drop leftover oper link_gp if present —
 * values are not copied; order is set in FreeSaaS or via data migrator mapping.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_users')) {
        return;
    }

    if (!Database::columnExists('base_users', 'num')) {
        $pdo->exec('ALTER TABLE `base_users` ADD COLUMN `num` INT NOT NULL DEFAULT 0');
        Database::clearSchemaCache();
    }

    if (Database::columnExists('base_users', 'link_gp')) {
        $pdo->exec('ALTER TABLE `base_users` DROP COLUMN `link_gp`');
        Database::clearSchemaCache();
    }
};
