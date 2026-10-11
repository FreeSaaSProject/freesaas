<?php
declare(strict_types=1);

/**
 * Webhook update_id dedup + one-time bot link codes (phone is not proof of ownership).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_bot_webhook_updates')) {
        $pdo->exec(
            'CREATE TABLE `base_bot_webhook_updates` (
                `platform` VARCHAR(16) NOT NULL,
                `channel` VARCHAR(16) NOT NULL,
                `update_id` BIGINT NOT NULL,
                `seen_at` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`platform`, `channel`, `update_id`),
                KEY `idx_bot_wh_seen` (`seen_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (!Database::tableExists('base_bot_link_codes')) {
        $pdo->exec(
            'CREATE TABLE `base_bot_link_codes` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `bot_source` TINYINT UNSIGNED NOT NULL,
                `id_entity` INT UNSIGNED NOT NULL,
                `code_hash` CHAR(64) NOT NULL,
                `expires_at` INT UNSIGNED NOT NULL,
                `used_at` INT UNSIGNED NULL DEFAULT NULL,
                `created_at` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_bot_link_code_hash` (`code_hash`),
                KEY `idx_bot_link_code_entity` (`bot_source`, `id_entity`),
                KEY `idx_bot_link_code_exp` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
