<?php
declare(strict_types=1);

/**
 * Client loyalty bonus ledger (oper base_bonuspay).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS base_bonuspay (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_USER INT UNSIGNED NULL,
            ID_EVENT_BONUS INT UNSIGNED NULL,
            ID_EVENT_DISCOUNT INT UNSIGNED NULL,
            ID_ORDER INT UNSIGNED NULL,
            DATE_IN INT UNSIGNED NOT NULL DEFAULT 0,
            DATE_OUT INT UNSIGNED NOT NULL DEFAULT 0,
            DESCRIPTION TEXT NULL,
            DIRECTION SMALLINT NOT NULL DEFAULT 0,
            SUM FLOAT NULL,
            DISMISSED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_bonuspay_user (ID_USER),
            KEY idx_bonuspay_date_in (DATE_IN),
            KEY idx_bonuspay_event_bonus (ID_EVENT_BONUS),
            KEY idx_bonuspay_event_discount (ID_EVENT_DISCOUNT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
};
