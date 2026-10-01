<?php
declare(strict_types=1);

/**
 * Client labels (ярлыки): shared dictionary with oper + FreeSaaS link table.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_shopusers_tags (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(200) NOT NULL DEFAULT '',
            ENABLED INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_client_tag_links (
            client_id INT UNSIGNED NOT NULL,
            tag_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (client_id, tag_id),
            KEY idx_tag (tag_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
};
