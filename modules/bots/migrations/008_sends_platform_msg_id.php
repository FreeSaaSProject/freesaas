<?php
declare(strict_types=1);

/**
 * Store platform message id after drain so confirmations can edit the sent message.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_telegram_sends')) {
        return;
    }
    if (!Database::columnExists('base_telegram_sends', 'PLATFORM_MSG_ID')) {
        $pdo->exec(
            'ALTER TABLE `base_telegram_sends`
             ADD COLUMN `PLATFORM_MSG_ID` VARCHAR(128) NULL DEFAULT NULL AFTER `PLATFORM`'
        );
        Database::clearSchemaCache();
    }
};
