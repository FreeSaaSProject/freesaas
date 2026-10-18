<?php
declare(strict_types=1);

/**
 * Per-role default shift lane (PRICE_COL) when assigning staff on nazop.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_admin_statuses')) {
        return;
    }
    if (!Database::columnExists('base_admin_statuses', 'DEFAULT_PRICE_COL')) {
        $pdo->exec(
            'ALTER TABLE `base_admin_statuses`
             ADD COLUMN `DEFAULT_PRICE_COL` INT UNSIGNED NOT NULL DEFAULT 0'
        );
        Database::clearSchemaCache();
    }
};
