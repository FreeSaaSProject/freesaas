<?php
declare(strict_types=1);

/**
 * Legacy oper base_bonuspay often predates ID_ORDER; CREATE IF NOT EXISTS leaves old schema.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_bonuspay')) {
        return;
    }
    if (!Database::columnExists('base_bonuspay', 'ID_ORDER')) {
        $pdo->exec(
            'ALTER TABLE `base_bonuspay`
             ADD COLUMN `ID_ORDER` INT UNSIGNED NULL DEFAULT NULL AFTER `ID_EVENT_DISCOUNT`'
        );
    }
};
