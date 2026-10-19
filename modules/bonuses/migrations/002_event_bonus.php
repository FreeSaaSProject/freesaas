<?php
declare(strict_types=1);

/**
 * Bonus intent for a calendar visit (owned by bonuses — not columns on base_events).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_event_bonus (
            ID_EVENT INT UNSIGNED NOT NULL,
            BONUS_USE TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            BONUS_DISCOUNT DECIMAL(12,2) NOT NULL DEFAULT 0,
            DTUPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID_EVENT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
