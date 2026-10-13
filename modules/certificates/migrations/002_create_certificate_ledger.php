<?php
declare(strict_types=1);

/**
 * Certificate money ledger (source of truth for balance).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_certificate_ledger (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_CERT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_OPERATOR INT UNSIGNED NOT NULL DEFAULT 0,
            ID_CLIENT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_EVENT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ORDER INT UNSIGNED NOT NULL DEFAULT 0,
            ID_REF INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DIRECTION TINYINT NOT NULL DEFAULT 1,
            AMOUNT DOUBLE NOT NULL DEFAULT 0,
            REASON VARCHAR(32) NOT NULL DEFAULT '',
            DESCR TEXT NULL,
            PRIMARY KEY (ID),
            KEY idx_cert_ledger_cert_dt (ID_CERT, DT_CREATE),
            KEY idx_cert_ledger_event (ID_EVENT),
            KEY idx_cert_ledger_order (ID_ORDER),
            KEY idx_cert_ledger_ref (ID_REF),
            KEY idx_cert_ledger_dt (DT_CREATE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
