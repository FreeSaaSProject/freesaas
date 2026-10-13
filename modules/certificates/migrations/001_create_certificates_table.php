<?php
declare(strict_types=1);

/**
 * Greenfield gift certificates (virgin-safe CREATE).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_certificates (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            CODE VARCHAR(64) NOT NULL DEFAULT '',
            ID_OPERATOR INT UNSIGNED NOT NULL DEFAULT 0,
            ID_BUYER INT UNSIGNED NOT NULL DEFAULT 0,
            ID_HOLDER INT UNSIGNED NOT NULL DEFAULT 0,
            ID_EVENT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            KIND TINYINT UNSIGNED NOT NULL DEFAULT 1,
            SALE_AMOUNT DOUBLE NOT NULL DEFAULT 0,
            FACE_VALUE DOUBLE NOT NULL DEFAULT 0,
            USED_AMOUNT DOUBLE NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_ACTIVATION_EXPIRE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_USE_EXPIRE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            EXPIRED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PARTIAL_FORBIDDEN TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            BONUS_FORBIDDEN TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            USE_LIMIT_PCT INT UNSIGNED NOT NULL DEFAULT 0,
            TYPE_IDS JSON NULL,
            PRODUCT_IDS JSON NULL,
            CONTENT JSON NULL,
            CONTENT_USED JSON NULL,
            DESCR TEXT NULL,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_certificates_code (CODE),
            KEY idx_certificates_holder (ID_HOLDER),
            KEY idx_certificates_buyer (ID_BUYER),
            KEY idx_certificates_operator (ID_OPERATOR),
            KEY idx_certificates_expire (EXPIRED, DT_USE_EXPIRE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
