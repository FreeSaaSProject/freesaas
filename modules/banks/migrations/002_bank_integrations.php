<?php
declare(strict_types=1);

/**
 * Multi-environment bank integrations (Alfa sandbox+prod credentials in DB).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_bank_integrations (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            PROVIDER VARCHAR(32) NOT NULL DEFAULT '',
            ENV VARCHAR(16) NOT NULL DEFAULT 'prod',
            LABEL VARCHAR(120) NOT NULL DEFAULT '',
            STATUS VARCHAR(32) NOT NULL DEFAULT 'draft',
            CLIENT_ID VARCHAR(191) NOT NULL DEFAULT '',
            CLIENT_SECRET MEDIUMTEXT NULL,
            API_KEY MEDIUMTEXT NULL,
            SCOPE VARCHAR(512) NOT NULL DEFAULT '',
            REDIRECT_URI VARCHAR(512) NOT NULL DEFAULT '',
            MTLS_CERT VARCHAR(512) NOT NULL DEFAULT '',
            MTLS_KEY VARCHAR(512) NOT NULL DEFAULT '',
            MTLS_CA VARCHAR(512) NOT NULL DEFAULT '',
            MTLS_PASS VARCHAR(255) NOT NULL DEFAULT '',
            META MEDIUMTEXT NULL,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_bank_int_provider_env (PROVIDER, ENV),
            KEY idx_bank_int_status (STATUS)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $cols = $pdo->query('SHOW COLUMNS FROM base_bank_connections')->fetchAll(PDO::FETCH_COLUMN);
    $have = [];
    foreach ($cols as $c) {
        $have[strtoupper((string)$c)] = true;
    }
    if (empty($have['ID_INTEGRATION'])) {
        $pdo->exec(
            'ALTER TABLE base_bank_connections
             ADD COLUMN ID_INTEGRATION INT UNSIGNED NOT NULL DEFAULT 0 AFTER PROVIDER,
             ADD KEY idx_bank_conn_integration (ID_INTEGRATION)'
        );
    }
};
