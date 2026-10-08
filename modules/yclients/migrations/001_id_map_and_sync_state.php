<?php
declare(strict_types=1);

/** External id map + job cursors for YClients pull importer. */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_yclients_id_map (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL DEFAULT 0,
            entity VARCHAR(32) NOT NULL DEFAULT '',
            ext_id VARCHAR(64) NOT NULL DEFAULT '',
            local_id INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_yclients_map (company_id, entity, ext_id),
            KEY idx_yclients_local (entity, local_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_yclients_sync_state (
            job VARCHAR(64) NOT NULL,
            cursor_value VARCHAR(255) NOT NULL DEFAULT '',
            last_run INT UNSIGNED NOT NULL DEFAULT 0,
            last_ok TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            meta_json MEDIUMTEXT NULL,
            PRIMARY KEY (job)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
