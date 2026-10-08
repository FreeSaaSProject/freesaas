<?php
declare(strict_types=1);

/**
 * Personal fitness + health tables (elos63 fit/health domain).
 * Owner column is ID_USER (strictly private per staff user).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_fit_foods (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            CCALS INT UNSIGNED NOT NULL DEFAULT 0,
            CARBS FLOAT UNSIGNED NOT NULL DEFAULT 0,
            PROTEINS FLOAT UNSIGNED NOT NULL DEFAULT 0,
            FATS FLOAT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_fit_foods_user (ID_USER),
            KEY idx_fit_foods_user_name (ID_USER, NAME)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_fit_foodlogs (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            ID_FOOD INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            WEIGHT FLOAT UNSIGNED NOT NULL DEFAULT 0,
            CCALS INT UNSIGNED NOT NULL DEFAULT 0,
            CARBS FLOAT UNSIGNED NOT NULL DEFAULT 0,
            PROTEINS FLOAT UNSIGNED NOT NULL DEFAULT 0,
            FATS FLOAT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_fit_foodlogs_user_dt (ID_USER, DT),
            KEY idx_fit_foodlogs_food (ID_FOOD)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_fitlogs (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_START INT UNSIGNED NOT NULL DEFAULT 0,
            DT_END INT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            CCALS INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_fitlogs_user_start (ID_USER, DT_START)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_fit_targets (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            WEIGHT FLOAT UNSIGNED NOT NULL DEFAULT 0,
            CCALS INT UNSIGNED NOT NULL DEFAULT 0,
            CARBS FLOAT UNSIGNED NOT NULL DEFAULT 0,
            PROTEINS FLOAT UNSIGNED NOT NULL DEFAULT 0,
            FATS FLOAT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (ID),
            KEY idx_fit_targets_user_dt (ID_USER, DT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_fit_weightlogs (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            WEIGHT FLOAT UNSIGNED NOT NULL DEFAULT 0,
            FAT FLOAT UNSIGNED NOT NULL DEFAULT 0,
            WATER FLOAT UNSIGNED NOT NULL DEFAULT 0,
            MUSCLES FLOAT UNSIGNED NOT NULL DEFAULT 0,
            FATCLASS TINYINT UNSIGNED NOT NULL DEFAULT 0,
            METACALS INT UNSIGNED NOT NULL DEFAULT 0,
            METAAGE INT UNSIGNED NOT NULL DEFAULT 0,
            INTFAT FLOAT UNSIGNED NOT NULL DEFAULT 0,
            WAIST INT UNSIGNED NOT NULL DEFAULT 0,
            HIPS INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_fit_weightlogs_user_dt (ID_USER, DT),
            KEY idx_fit_weightlogs_user_dt (ID_USER, DT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_healths_medicines (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (ID),
            KEY idx_healths_medicines_user (ID_USER),
            KEY idx_healths_medicines_user_name (ID_USER, NAME)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_healths_symptoms (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY (ID),
            KEY idx_healths_symptoms_user (ID_USER),
            KEY idx_healths_symptoms_user_name (ID_USER, NAME)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_healths_logs (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            ID_SYMPTOM INT UNSIGNED NOT NULL DEFAULT 0,
            ID_MEDICINE INT UNSIGNED NOT NULL DEFAULT 0,
            LEVEL TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            DESCR VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (ID),
            KEY idx_healths_logs_user_dt (ID_USER, DT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_healths_recipes (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            DOCTOR VARCHAR(64) NOT NULL DEFAULT '',
            DATA TEXT NOT NULL,
            PRIMARY KEY (ID),
            KEY idx_healths_recipes_user_dt (ID_USER, DT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
