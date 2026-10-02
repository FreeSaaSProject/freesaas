<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    if (Database::tableExists('base_user_prefs')) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE `base_user_prefs` (
            `user_id` INT UNSIGNED NOT NULL,
            `pref_key` VARCHAR(64) NOT NULL,
            `pref_value` MEDIUMTEXT NOT NULL,
            `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`user_id`, `pref_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
