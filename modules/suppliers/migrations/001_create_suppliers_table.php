<?php
declare(strict_types=1);

/**
 * Suppliers. Shared with legacy oper — keep uppercase column names.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_suppliers (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            NAME VARCHAR(40) NOT NULL DEFAULT '',
            DESCR VARCHAR(255) NOT NULL DEFAULT '',
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            DOGOVOR VARCHAR(20) NOT NULL DEFAULT '',
            UR_NAME VARCHAR(255) NOT NULL DEFAULT '',
            INN VARCHAR(40) NOT NULL DEFAULT '',
            KPP VARCHAR(40) NOT NULL DEFAULT '',
            RS VARCHAR(40) NOT NULL DEFAULT '',
            KS VARCHAR(40) NOT NULL DEFAULT '',
            BIK VARCHAR(40) NOT NULL DEFAULT '',
            BANK VARCHAR(40) NOT NULL DEFAULT '',
            DT INT NOT NULL DEFAULT 0,
            SUMM_FREE_DELIV INT UNSIGNED NOT NULL DEFAULT 0,
            CONTACT_DATA VARCHAR(255) NOT NULL DEFAULT '',
            IS_GOODS_SUP TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
