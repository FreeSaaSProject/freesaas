<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_acquiring_payments (
            ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ORDER_ID INT UNSIGNED NOT NULL DEFAULT 0,
            CLIENT_ID INT UNSIGNED NOT NULL DEFAULT 0,
            PROVIDER VARCHAR(32) NOT NULL DEFAULT '',
            PROVIDER_REF VARCHAR(128) NOT NULL DEFAULT '',
            AMOUNT DECIMAL(12,2) NOT NULL DEFAULT 0,
            CURRENCY CHAR(3) NOT NULL DEFAULT 'RUB',
            STATUS VARCHAR(32) NOT NULL DEFAULT 'pending',
            PAY_URL TEXT NULL,
            META_JSON JSON NULL,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_acq_order (ORDER_ID),
            KEY idx_acq_client (CLIENT_ID),
            KEY idx_acq_provider_ref (PROVIDER, PROVIDER_REF),
            KEY idx_acq_status (STATUS)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_acquiring_webhook_log (
            ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            PROVIDER VARCHAR(32) NOT NULL DEFAULT '',
            PAYMENT_ID BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PAYLOAD MEDIUMTEXT NULL,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_acq_wh_provider (PROVIDER),
            KEY idx_acq_wh_payment (PAYMENT_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
