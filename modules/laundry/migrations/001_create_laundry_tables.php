<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_laundry_accounts (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            NAME VARCHAR(60) NOT NULL DEFAULT '',
            DESCR VARCHAR(255) NOT NULL DEFAULT '',
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            ENABLED TINYINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_laundry_item_types (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            CODE VARCHAR(30) NOT NULL DEFAULT '',
            NAME VARCHAR(60) NOT NULL DEFAULT '',
            SORT INT NOT NULL DEFAULT 0,
            ENABLED TINYINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_laundry_type_code (CODE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_laundry_moves (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_ACCOUNT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ACCOUNT_TO INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ITEM_TYPE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_MOVEMENT_LINKED INT UNSIGNED NOT NULL DEFAULT 0,
            ID_SHIPMENT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_RETURN INT UNSIGNED NOT NULL DEFAULT 0,
            DIRECTION INT NOT NULL DEFAULT 0,
            SUMM INT UNSIGNED NOT NULL DEFAULT 0,
            DESCR VARCHAR(255) NOT NULL DEFAULT '',
            WEIGHT FLOAT UNSIGNED NOT NULL DEFAULT 0,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            DELETED TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_la_acc (ID_ACCOUNT),
            KEY idx_la_type (ID_ITEM_TYPE),
            KEY idx_la_linked (ID_MOVEMENT_LINKED),
            KEY idx_la_ship (ID_SHIPMENT),
            KEY idx_la_ret (ID_RETURN)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_laundry_shipments (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_ACCOUNT_FROM INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ACCOUNT_TO INT UNSIGNED NOT NULL DEFAULT 0,
            ID_NEWS INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            STATUS VARCHAR(16) NOT NULL DEFAULT 'open',
            DESCR VARCHAR(255) NOT NULL DEFAULT '',
            WEIGHT FLOAT UNSIGNED NOT NULL DEFAULT 0,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_ls_status (STATUS),
            KEY idx_ls_dt (DT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_laundry_shipment_lines (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_SHIPMENT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ITEM_TYPE INT UNSIGNED NOT NULL DEFAULT 0,
            QTY_SENT INT UNSIGNED NOT NULL DEFAULT 0,
            QTY_RETURNED INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_lsl_ship (ID_SHIPMENT),
            KEY idx_lsl_type (ID_ITEM_TYPE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_laundry_returns (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_ACCOUNT_FROM INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ACCOUNT_TO INT UNSIGNED NOT NULL DEFAULT 0,
            ID_NEWS INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DESCR VARCHAR(255) NOT NULL DEFAULT '',
            WEIGHT FLOAT UNSIGNED NOT NULL DEFAULT 0,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_lr_dt (DT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_laundry_return_lines (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_RETURN INT UNSIGNED NOT NULL DEFAULT 0,
            ID_SHIPMENT_LINE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ITEM_TYPE INT UNSIGNED NOT NULL DEFAULT 0,
            QTY INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_lrl_ret (ID_RETURN),
            KEY idx_lrl_line (ID_SHIPMENT_LINE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Seed accounts if empty
    $n = (int)$pdo->query('SELECT COUNT(*) FROM base_laundry_accounts')->fetchColumn();
    if ($n === 0) {
        $now = time();
        $st = $pdo->prepare('INSERT INTO base_laundry_accounts (NAME, DESCR, DT, ENABLED) VALUES (?, ?, ?, 1)');
        $st->execute(['ТЕРМЫ', '', $now]);
        $st->execute(['ИП Терем стирка', '', $now]);
    }

    // Seed item types if empty
    $n = (int)$pdo->query('SELECT COUNT(*) FROM base_laundry_item_types')->fetchColumn();
    if ($n === 0) {
        $st = $pdo->prepare('INSERT INTO base_laundry_item_types (CODE, NAME, SORT, ENABLED) VALUES (?, ?, ?, 1)');
        $st->execute(['sheets', 'Простыни', 10]);
        $st->execute(['tees', 'Майки', 20]);
        $st->execute(['shorts', 'Шорты', 30]);
        $st->execute(['curtains', 'Шторы', 40]);
    }
};
