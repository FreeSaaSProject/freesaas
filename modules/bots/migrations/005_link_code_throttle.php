<?php
declare(strict_types=1);

/**
 * Link-code strength: fail_count on codes + per-chat throttle table.
 */
return static function (PDO $pdo): void {
    if (Database::tableExists('base_bot_link_codes')
        && !Database::columnExists('base_bot_link_codes', 'fail_count')
    ) {
        $pdo->exec(
            'ALTER TABLE `base_bot_link_codes`
             ADD COLUMN `fail_count` TINYINT UNSIGNED NOT NULL DEFAULT 0'
        );
    }

    if (!Database::tableExists('base_bot_link_throttle')) {
        $pdo->exec(
            'CREATE TABLE `base_bot_link_throttle` (
                `platform` VARCHAR(16) NOT NULL,
                `channel` VARCHAR(16) NOT NULL,
                `chat_id` VARCHAR(64) NOT NULL,
                `fail_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `locked_until` INT UNSIGNED NULL DEFAULT NULL,
                `last_fail_at` INT UNSIGNED NULL DEFAULT NULL,
                PRIMARY KEY (`platform`, `channel`, `chat_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
