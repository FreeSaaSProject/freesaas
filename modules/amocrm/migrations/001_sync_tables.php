<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_amocrm_id_map (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            account VARCHAR(128) NOT NULL DEFAULT '',
            entity VARCHAR(32) NOT NULL DEFAULT '',
            ext_id VARCHAR(64) NOT NULL DEFAULT '',
            local_id INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_amocrm_map (account, entity, ext_id),
            KEY idx_amocrm_local (entity, local_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_amocrm_sync_state (
            job VARCHAR(64) NOT NULL,
            cursor_value VARCHAR(255) NOT NULL DEFAULT '',
            last_run INT UNSIGNED NOT NULL DEFAULT 0,
            last_ok TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            meta_json MEDIUMTEXT NULL,
            PRIMARY KEY (job)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_amocrm_outbox (
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
            KEY idx_amocrm_outbox_status (status, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_amocrm_webhook_events (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(190) NOT NULL DEFAULT '',
            seen_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_amocrm_wh (event_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
