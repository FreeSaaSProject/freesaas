<?php
declare(strict_types=1);

/**
 * Slim catalog: categories, products, product relations.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_categories (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(64) NOT NULL DEFAULT '',
            name_short VARCHAR(20) NOT NULL DEFAULT '',
            parent_id INT NOT NULL DEFAULT 0,
            num INT NOT NULL DEFAULT 0,
            hidden TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            no_cart TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            executors MEDIUMTEXT NULL,
            review MEDIUMTEXT NULL,
            PRIMARY KEY (id),
            KEY idx_parent (parent_id),
            KEY idx_num (num),
            KEY idx_hidden (hidden)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_products (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            category INT NOT NULL DEFAULT 0,
            name VARCHAR(255) NOT NULL DEFAULT '',
            name_price VARCHAR(200) NOT NULL DEFAULT '',
            description MEDIUMTEXT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            num INT NOT NULL DEFAULT 0,
            price FLOAT NOT NULL DEFAULT 0,
            price2 FLOAT NOT NULL DEFAULT 0,
            price3 FLOAT NOT NULL DEFAULT 0,
            price4 FLOAT UNSIGNED NOT NULL DEFAULT 0,
            price5 FLOAT UNSIGNED NOT NULL DEFAULT 0,
            is_material TINYINT(1) NOT NULL DEFAULT 0,
            type_id INT NOT NULL DEFAULT 0,
            time_sec INT NOT NULL DEFAULT 0,
            time_net INT UNSIGNED NOT NULL DEFAULT 0,
            descr_proc MEDIUMTEXT NULL,
            unit_id INT UNSIGNED NOT NULL DEFAULT 0,
            unit_use_id INT UNSIGNED NOT NULL DEFAULT 0,
            supplier_id INT UNSIGNED NOT NULL DEFAULT 0,
            stock_min INT UNSIGNED NOT NULL DEFAULT 0,
            stock_max INT UNSIGNED NOT NULL DEFAULT 0,
            hide_on_site TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            hide_in_history TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            onec_id VARCHAR(40) NOT NULL DEFAULT '',
            dop_cat VARCHAR(255) NOT NULL DEFAULT '',
            amount_done INT UNSIGNED NOT NULL DEFAULT 0,
            last_admin_id INT UNSIGNED NOT NULL DEFAULT 0,
            last_admin_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_category (category),
            KEY idx_enabled (enabled),
            KEY idx_material (is_material),
            KEY idx_name (name(100)),
            KEY idx_onec (onec_id),
            KEY idx_num (num)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_product_relations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_from_id INT UNSIGNED NOT NULL DEFAULT 0,
            product_to_id INT UNSIGNED NOT NULL DEFAULT 0,
            type ENUM('addon','upgrade','component') NOT NULL DEFAULT 'addon',
            benefit MEDIUMTEXT NULL,
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_from_type (product_from_id, type),
            KEY idx_to (product_to_id),
            KEY idx_enabled (enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
