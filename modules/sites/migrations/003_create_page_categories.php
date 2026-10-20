<?php
declare(strict_types=1);

/**
 * Site articles / page tree (base_page_categories).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_page_categories (
            id INT NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NULL,
            num INT NOT NULL DEFAULT 1,
            parent_to INT NOT NULL DEFAULT 0,
            content MEDIUMTEXT NOT NULL,
            link VARCHAR(255) NOT NULL DEFAULT '',
            linkRW VARCHAR(255) NOT NULL DEFAULT '',
            enabled TINYINT UNSIGNED NOT NULL DEFAULT 1,
            datas INT UNSIGNED NOT NULL DEFAULT 0,
            HASHTAGS MEDIUMTEXT NULL,
            BG_URL VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY parent_to (parent_to)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
