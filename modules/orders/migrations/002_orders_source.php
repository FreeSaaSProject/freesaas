<?php
declare(strict_types=1);

/**
 * Order channel marker: api_termy | api | site | staff (callers set on create).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_orders')) {
        return;
    }
    if (!Database::columnExists('base_orders', 'SOURCE')) {
        $pdo->exec(
            'ALTER TABLE `base_orders`
             ADD COLUMN `SOURCE` VARCHAR(32) NULL DEFAULT NULL AFTER `FREE_TICKET`,
             ADD KEY `idx_source` (`SOURCE`)'
        );
        Database::clearSchemaCache();
    }
};
