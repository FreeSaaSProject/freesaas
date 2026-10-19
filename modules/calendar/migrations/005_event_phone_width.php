<?php
declare(strict_types=1);

/** Store normalized 7XXXXXXXXXX (11 digits); legacy dumps had VARCHAR(10). */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_events') || !Database::columnExists('base_events', 'PHONE')) {
        return;
    }
    $pdo->exec("ALTER TABLE `base_events` MODIFY COLUMN `PHONE` VARCHAR(16) NOT NULL DEFAULT ''");
};
