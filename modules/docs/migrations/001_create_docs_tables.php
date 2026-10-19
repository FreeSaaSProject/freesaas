<?php
declare(strict_types=1);

/**
 * Document engine: definitions + immutable versions + instances (incl. medcards),
 * narrow field index and autofill proposals.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_doc_defs (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            CODE VARCHAR(64) NOT NULL DEFAULT '',
            KIND VARCHAR(32) NOT NULL DEFAULT 'consent',
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            SCOPE VARCHAR(16) NOT NULL DEFAULT 'event',
            TRIGGER_MODE VARCHAR(16) NOT NULL DEFAULT 'manual',
            TRIGGER_TYPES JSON NULL,
            TRIGGER_PROCS JSON NULL,
            EXPIRE_DAYS INT UNSIGNED NOT NULL DEFAULT 0,
            REQUIRE_MEDCARD TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            NUM INT NOT NULL DEFAULT 0,
            ID_VERSION_CURRENT INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_doc_defs_code (CODE),
            KEY idx_doc_defs_kind (KIND, ENABLED)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_doc_def_versions (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_DEF INT UNSIGNED NOT NULL DEFAULT 0,
            VERSION INT UNSIGNED NOT NULL DEFAULT 1,
            SCHEMA_JSON JSON NULL,
            PRINT_HTML MEDIUMTEXT NULL,
            ORIENTATION VARCHAR(10) NOT NULL DEFAULT 'portrait',
            FORMAT VARCHAR(10) NOT NULL DEFAULT 'A4',
            COPIES TINYINT UNSIGNED NOT NULL DEFAULT 1,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_doc_def_version (ID_DEF, VERSION)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_doc_instances (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_DEF INT UNSIGNED NOT NULL DEFAULT 0,
            ID_VERSION INT UNSIGNED NOT NULL DEFAULT 0,
            KIND VARCHAR(32) NOT NULL DEFAULT '',
            ID_CLIENT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_EVENT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_PROCEDURE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_PARENT INT UNSIGNED NOT NULL DEFAULT 0,
            STATUS VARCHAR(16) NOT NULL DEFAULT 'draft',
            PAYLOAD JSON NULL,
            PRINT_SNAPSHOT MEDIUMTEXT NULL,
            HASH CHAR(32) NOT NULL DEFAULT '',
            HASH_EXT CHAR(32) NOT NULL DEFAULT '',
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_PRINT INT UNSIGNED NOT NULL DEFAULT 0,
            DT_EXPIRE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN_PRINT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN_SIGNED INT UNSIGNED NOT NULL DEFAULT 0,
            DT_SIGNED INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_doc_inst_hash (HASH),
            UNIQUE KEY uq_doc_inst_hash_ext (HASH_EXT),
            KEY idx_doc_inst_client (ID_CLIENT, KIND, STATUS),
            KEY idx_doc_inst_event (ID_EVENT),
            KEY idx_doc_inst_procedure (ID_PROCEDURE),
            KEY idx_doc_inst_def (ID_DEF, ID_CLIENT, DT_EXPIRE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_doc_field_index (
            ID_INSTANCE INT UNSIGNED NOT NULL,
            FIELD_ID VARCHAR(64) NOT NULL,
            VALUE_STR VARCHAR(191) NOT NULL DEFAULT '',
            KIND VARCHAR(32) NOT NULL DEFAULT '',
            ID_CLIENT INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID_INSTANCE, FIELD_ID, VALUE_STR),
            KEY idx_doc_field_value (FIELD_ID, VALUE_STR),
            KEY idx_doc_field_client (ID_CLIENT, FIELD_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_doc_proposals (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_INSTANCE INT UNSIGNED NOT NULL DEFAULT 0,
            FIELD_ID VARCHAR(64) NOT NULL DEFAULT '',
            VALUE_JSON JSON NULL,
            SOURCE VARCHAR(32) NOT NULL DEFAULT '',
            CONFIDENCE FLOAT NOT NULL DEFAULT 0,
            META_JSON JSON NULL,
            STATUS TINYINT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_DECIDED INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN_DECIDED INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_doc_prop_instance (ID_INSTANCE, STATUS)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
