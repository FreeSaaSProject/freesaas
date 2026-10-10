<?php
declare(strict_types=1);

/** Remove unused legacy can_be_sold (admin sell flag; never used in FreeSaaS). */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_products')) {
        return;
    }
    if (!Database::columnExists('base_products', 'can_be_sold')) {
        return;
    }
    $pdo->exec('ALTER TABLE `base_products` DROP COLUMN `can_be_sold`');
};
