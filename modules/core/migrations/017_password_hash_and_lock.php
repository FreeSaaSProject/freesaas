<?php
declare(strict_types=1);

/**
 * Widen password for password_hash() (argon2id ~97 / bcrypt 60),
 * add lockout observability columns, one-time login links table.
 */
return static function (PDO $pdo): void {
    if (Database::tableExists('base_users')) {
        $st = $pdo->query(
            "SELECT CHARACTER_MAXIMUM_LENGTH AS len, DATA_TYPE AS typ
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_users' AND COLUMN_NAME = 'password'
             LIMIT 1"
        );
        $meta = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        $len = is_array($meta) ? (int)($meta['len'] ?? 0) : 0;
        if ($len > 0 && $len < 255) {
            $pdo->exec("ALTER TABLE `base_users` MODIFY COLUMN `password` VARCHAR(255) NOT NULL DEFAULT ''");
        }

        if (!Database::columnExists('base_users', 'locked_until')) {
            $pdo->exec('ALTER TABLE `base_users` ADD COLUMN `locked_until` DATETIME NULL DEFAULT NULL');
        }
        if (!Database::columnExists('base_users', 'last_failed_at')) {
            $pdo->exec('ALTER TABLE `base_users` ADD COLUMN `last_failed_at` DATETIME NULL DEFAULT NULL');
        }
    }

    if (!Database::tableExists('base_user_login_links')) {
        $pdo->exec(
            'CREATE TABLE `base_user_login_links` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_user` INT UNSIGNED NOT NULL,
                `token_hash` CHAR(64) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `used_at` DATETIME NULL DEFAULT NULL,
                `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_login_link_token` (`token_hash`),
                KEY `idx_login_link_user` (`id_user`),
                KEY `idx_login_link_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
