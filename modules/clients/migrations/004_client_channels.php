<?php
declare(strict_types=1);

/** Communication channels of a client (phone, telegram, max, whatsapp, email, …) beyond base_clients.tel/mail. */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_client_channels (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id INT UNSIGNED NOT NULL DEFAULT 0,
            type VARCHAR(16) NOT NULL DEFAULT '',
            value VARCHAR(255) NOT NULL DEFAULT '',
            value_norm VARCHAR(255) NOT NULL DEFAULT '',
            label VARCHAR(64) NOT NULL DEFAULT '',
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_client_channel_client (client_id, sort_order),
            KEY idx_client_channel_lookup (type, value_norm)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
