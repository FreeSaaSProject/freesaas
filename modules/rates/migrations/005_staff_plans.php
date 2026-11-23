<?php
declare(strict_types=1);

/** Month plan / hour norm / bonus tiers per specialist (Berdnikova-style FOT). */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_staff_plans (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_OPERATOR INT UNSIGNED NOT NULL DEFAULT 0,
            PERIOD_YM CHAR(7) NOT NULL DEFAULT '',
            PLAN_SUM DOUBLE NOT NULL DEFAULT 0,
            NORM_RUB_HOUR DOUBLE NOT NULL DEFAULT 0,
            BONUS_TIERS TEXT NULL,
            NOTE VARCHAR(255) NOT NULL DEFAULT '',
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_staff_plan_op_ym (ID_OPERATOR, PERIOD_YM),
            KEY idx_staff_plan_ym (PERIOD_YM)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
