<?php
declare(strict_types=1);

/**
 * Website orders. Shared with legacy oper / site — keep uppercase column names.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_orders (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE_BY_USER INT UNSIGNED NOT NULL DEFAULT 0,
            DT_SAVE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_EVENT_NEW INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            ID_SERT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_EVENT_PAID INT UNSIGNED NOT NULL DEFAULT 0,
            ID_EVENT_USE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_COSMETOLOG INT UNSIGNED NOT NULL DEFAULT 0,
            CART TEXT NOT NULL,
            PHONE VARCHAR(11) NOT NULL DEFAULT '',
            MAIL VARCHAR(50) NOT NULL DEFAULT '',
            STATUS VARCHAR(255) NOT NULL DEFAULT '',
            COMMENT TEXT NOT NULL,
            PPP_LOG TEXT NOT NULL,
            EPAID TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            SBER_ID VARCHAR(255) NOT NULL DEFAULT '',
            SBER_LINK VARCHAR(255) NOT NULL DEFAULT '',
            SBER_STATUS TINYINT UNSIGNED NOT NULL DEFAULT 0,
            SUMM FLOAT UNSIGNED NOT NULL DEFAULT 0,
            SUMM_REAL FLOAT UNSIGNED NOT NULL DEFAULT 0,
            SUMM_SBER FLOAT UNSIGNED NOT NULL DEFAULT 0,
            SUMM_BONUS INT UNSIGNED NOT NULL DEFAULT 0,
            UID VARCHAR(15) NOT NULL DEFAULT '',
            ID_PROMOCODE INT UNSIGNED NOT NULL DEFAULT 0,
            TEXT_PROMOCODE VARCHAR(255) NOT NULL DEFAULT '',
            FREE_TICKET TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_dt_event (DT_EVENT_NEW),
            KEY idx_status (STATUS),
            KEY idx_id_user (ID_USER),
            KEY idx_id_promocode (ID_PROMOCODE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $adds = [
        'SUMM_BONUS' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER SUMM_SBER',
        'FREE_TICKET' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER TEXT_PROMOCODE',
    ];
    foreach ($adds as $col => $def) {
        if (!Database::columnExists('base_orders', $col)) {
            $pdo->exec('ALTER TABLE `base_orders` ADD COLUMN `' . $col . '` ' . $def);
        }
    }
};
