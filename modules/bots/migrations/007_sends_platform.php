<?php
declare(strict_types=1);

/**
 * PLATFORM on outbound queue: direct chat_id sends (max|telegram|both) without base_bot_links.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_telegram_sends')) {
        return;
    }
    if (!Database::columnExists('base_telegram_sends', 'PLATFORM')) {
        $pdo->exec(
            'ALTER TABLE `base_telegram_sends`
             ADD COLUMN `PLATFORM` VARCHAR(16) NULL DEFAULT NULL AFTER `CHAT_ID`'
        );
        Database::clearSchemaCache();
    }
};
