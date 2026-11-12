<?php
declare(strict_types=1);

/**
 * Edge workers hosts (one row per machine with the workers gateway).
 * Existing ASR push workers are copied with the same ID so base_asr_leases.WORKER_ID stays valid.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_edge_hosts')) {
        $pdo->exec(
            'CREATE TABLE `base_edge_hosts` (
                `ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `NAME` VARCHAR(100) NOT NULL DEFAULT \'\',
                `TRANSPORT` VARCHAR(16) NOT NULL DEFAULT \'inbound\',
                `BASE_URL` VARCHAR(255) NOT NULL DEFAULT \'\',
                `KEY_ID` VARCHAR(64) NOT NULL DEFAULT \'owner\',
                `SECRET` VARCHAR(128) NOT NULL DEFAULT \'\',
                `SECRET_NEXT` VARCHAR(128) NOT NULL DEFAULT \'\',
                `PIN_SHA256` VARCHAR(64) NOT NULL DEFAULT \'\',
                `ENABLED` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `PLUGINS_JSON` TEXT NULL,
                `API_VERSION` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `LAST_SEEN` INT UNSIGNED NOT NULL DEFAULT 0,
                `LAST_STATUS` MEDIUMTEXT NULL,
                `LAST_ERROR` VARCHAR(500) NOT NULL DEFAULT \'\',
                `FAILS` INT UNSIGNED NOT NULL DEFAULT 0,
                `BACKOFF_UNTIL` INT UNSIGNED NOT NULL DEFAULT 0,
                `DT_CREATED` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`ID`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        Database::clearSchemaCache();
    }

    if (!Database::tableExists('base_asr_push_workers')) {
        return;
    }
    $pdo->exec(
        "INSERT IGNORE INTO base_edge_hosts
            (ID, NAME, TRANSPORT, BASE_URL, KEY_ID, SECRET, SECRET_NEXT, PIN_SHA256, ENABLED, PLUGINS_JSON,
             API_VERSION, LAST_SEEN, LAST_STATUS, LAST_ERROR, FAILS, BACKOFF_UNTIL, DT_CREATED)
         SELECT ID, NAME, 'inbound', BASE_URL, 'owner', SECRET, SECRET_NEXT, PIN_SHA256, ENABLED,
                CONCAT('{\"asr\":{\"enabled\":', IF(ENABLED = 1, 'true', 'false'), ',\"max_inflight\":', MAX_INFLIGHT, '}}'),
                1, LAST_SEEN,
                IF(LAST_STATUS IS NULL OR LAST_STATUS = '', NULL, CONCAT('{\"asr\":', LAST_STATUS, '}')),
                LAST_ERROR, FAILS, BACKOFF_UNTIL, DT_CREATED
           FROM base_asr_push_workers"
    );
};
