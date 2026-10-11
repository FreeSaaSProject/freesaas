<?php
declare(strict_types=1);

/**
 * Session invalidation token: bump auth_epoch to kill all live sessions for a user.
 * Default 0 → existing sessions (missing key) stay valid until first bump.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_users')) {
        return;
    }
    if (!Database::columnExists('base_users', 'auth_epoch')) {
        $pdo->exec(
            'ALTER TABLE `base_users` ADD COLUMN `auth_epoch` INT UNSIGNED NOT NULL DEFAULT 0'
        );
    }
};
