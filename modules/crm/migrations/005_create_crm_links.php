<?php
declare(strict_types=1);

/** Links from CRM entities (deal/company) to records owned by other modules (tickets, poll invites, …). */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_crm_links (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity VARCHAR(16) NOT NULL DEFAULT '',
            entity_id INT UNSIGNED NOT NULL DEFAULT 0,
            target VARCHAR(32) NOT NULL DEFAULT '',
            target_id INT UNSIGNED NOT NULL DEFAULT 0,
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_crm_link (entity, entity_id, target, target_id),
            KEY idx_crm_link_target (target, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
