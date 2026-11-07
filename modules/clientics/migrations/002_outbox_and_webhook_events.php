<?php
declare(strict_types=1);

/** Clientics dual-run: outbox + webhook idempotency. */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_clientics_outbox (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity VARCHAR(32) NOT NULL DEFAULT '',
            local_id INT UNSIGNED NOT NULL DEFAULT 0,
            op VARCHAR(16) NOT NULL DEFAULT 'upsert',
            payload_json MEDIUMTEXT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_clientics_outbox_status (status, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_clientics_webhook_events (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(190) NOT NULL DEFAULT '',
            seen_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_clientics_wh (event_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
