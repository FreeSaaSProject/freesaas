<?php
declare(strict_types=1);

/**
 * Own recipes + synthetic food link (oper base_fit_recipes / items / ID_SOURCE_RECIPE).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_fit_recipes (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            PORTIONS INT UNSIGNED NOT NULL DEFAULT 1,
            WEIGHT_COOKED_G FLOAT NULL DEFAULT NULL,
            CCALS FLOAT NOT NULL DEFAULT 0,
            PROTEINS FLOAT NOT NULL DEFAULT 0,
            FATS FLOAT NOT NULL DEFAULT 0,
            CARBS FLOAT NOT NULL DEFAULT 0,
            STEPS TEXT NULL,
            INGREDIENTS TEXT NULL,
            SOURCE VARCHAR(32) NOT NULL DEFAULT 'manual',
            ID_FOOD INT UNSIGNED NULL DEFAULT NULL,
            DISABLED TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_fit_recipes_user (ID_USER),
            KEY idx_fit_recipes_user_name (ID_USER, NAME),
            KEY idx_fit_recipes_food (ID_FOOD)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_fit_recipe_items (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_RECIPE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_FOOD INT UNSIGNED NOT NULL DEFAULT 0,
            GRAMS FLOAT UNSIGNED NOT NULL DEFAULT 0,
            SORT_ORDER INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_fit_recipe_items_recipe (ID_RECIPE),
            KEY idx_fit_recipe_items_food (ID_FOOD)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (Database::tableExists('base_fit_foods') && !Database::columnExists('base_fit_foods', 'ID_SOURCE_RECIPE')) {
        $pdo->exec(
            'ALTER TABLE base_fit_foods
             ADD COLUMN ID_SOURCE_RECIPE INT UNSIGNED NULL DEFAULT NULL,
             ADD KEY idx_fit_foods_source_recipe (ID_SOURCE_RECIPE)'
        );
        Database::clearSchemaCache();
    }
};
