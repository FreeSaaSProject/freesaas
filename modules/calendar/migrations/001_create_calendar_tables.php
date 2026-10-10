<?php
declare(strict_types=1);

/**
 * Appointment events. Shared with legacy oper — keep uppercase columns.
 * Cabinets → cabinets module; workweeks → workweeks module.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_events (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_OPERATOR INT UNSIGNED NOT NULL DEFAULT 0,
            ID_OPERATOR_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            ID_PROMO_ACT INT UNSIGNED NOT NULL DEFAULT 0,
            DTCREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DTSTART INT UNSIGNED NOT NULL DEFAULT 0,
            DTEND INT UNSIGNED NOT NULL DEFAULT 0,
            DTUPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            FIX_MOVE TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            CONTENT TEXT NOT NULL,
            CONTENT_PRICE TEXT NOT NULL,
            SMS TEXT NOT NULL,
            DESCR TEXT NOT NULL,
            NODEFTIMEXPL TEXT NOT NULL,
            PHONE VARCHAR(11) NOT NULL DEFAULT '',
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            BONUS_DISCOUNT FLOAT NOT NULL DEFAULT 0,
            BONUS_USE TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PAY TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PPP_LOG TEXT NOT NULL,
            IS_BLOCK TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            REAL_SUMM FLOAT NOT NULL DEFAULT 0,
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            DTENABLE INT UNSIGNED NOT NULL DEFAULT 0,
            OPERATOR_ENABLED INT UNSIGNED NOT NULL DEFAULT 0,
            UPSALE_DATA MEDIUMTEXT NOT NULL,
            CONTENT_DATA MEDIUMTEXT NOT NULL,
            NONEWEXPL VARCHAR(255) NOT NULL DEFAULT '',
            EVT_TIME_RECOM INT UNSIGNED NOT NULL DEFAULT 0,
            EVT_TIME_REAL INT UNSIGNED NOT NULL DEFAULT 0,
            EVT_COSM_ACCEPT TINYINT UNSIGNED NOT NULL DEFAULT 0,
            EVT_DECLINE_REASON TEXT NOT NULL,
            EVT_DT_COSM_MOD INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_dtstart (DTSTART),
            KEY idx_operator (ID_OPERATOR),
            KEY idx_user (ID_USER),
            KEY idx_enabled (ENABLED)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_events_changes (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_EVENT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_OPERATOR INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            TYPE VARCHAR(32) NOT NULL DEFAULT '',
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            TEXT_OLD TEXT NOT NULL,
            TEXT_NEW TEXT NOT NULL,
            UNDO_SQL TEXT NOT NULL,
            FIX_MOVE TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_event (ID_EVENT),
            KEY idx_dt (DT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $eventAdds = [
        'EVT_TIME_RECOM' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER ENABLED',
        'EVT_TIME_REAL' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER EVT_TIME_RECOM',
        'EVT_COSM_ACCEPT' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER EVT_TIME_REAL',
        'EVT_DECLINE_REASON' => 'TEXT NOT NULL AFTER EVT_COSM_ACCEPT',
        'EVT_DT_COSM_MOD' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER EVT_DECLINE_REASON',
    ];
    foreach ($eventAdds as $col => $def) {
        if (!Database::columnExists('base_events', $col)) {
            $pdo->exec('ALTER TABLE `base_events` ADD COLUMN `' . $col . '` ' . $def);
        }
    }
};
