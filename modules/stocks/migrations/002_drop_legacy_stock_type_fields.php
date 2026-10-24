<?php
declare(strict_types=1);

/**
 * Drop unused warehouse type / virtual / autocontrol fields (oper leftovers).
 * Deletes TYPE=2 virtual stock rows first (no sticker movements expected).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_stocks')) {
        return;
    }

    if (Database::columnExists('base_stocks', 'TYPE')) {
        $pdo->exec('DELETE FROM base_stocks WHERE TYPE = 2');
    }

    foreach (['VRT_STOCK_ID', 'AUTOCONTROL_REQ', 'TYPE'] as $col) {
        if (Database::columnExists('base_stocks', $col)) {
            $pdo->exec('ALTER TABLE `base_stocks` DROP COLUMN `' . $col . '`');
        }
    }
};
