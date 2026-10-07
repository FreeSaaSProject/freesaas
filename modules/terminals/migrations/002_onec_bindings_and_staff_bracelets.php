<?php
declare(strict_types=1);

/**
 * Current 1C ticket binding per client + permanent staff NFC bracelet.
 * Own tables — no ALTER of base_clients / base_users.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_client_onec_bindings (
            client_id INT UNSIGNED NOT NULL,
            onec_id VARCHAR(60) NOT NULL DEFAULT '',
            dt_valid INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (client_id),
            KEY idx_onec_id (onec_id),
            KEY idx_dt_valid (dt_valid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_staff_bracelets (
            user_id INT UNSIGNED NOT NULL,
            uid VARCHAR(32) NOT NULL DEFAULT '',
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id),
            UNIQUE KEY uid (uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
};
