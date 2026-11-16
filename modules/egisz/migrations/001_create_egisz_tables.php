<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_egisz_client_ids (
            ID_CLIENT INT UNSIGNED NOT NULL,
            SNILS VARCHAR(14) NULL DEFAULT NULL,
            OMS_POLICY VARCHAR(32) NULL DEFAULT NULL,
            OMS_INSURER VARCHAR(255) NULL DEFAULT NULL,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID_CLIENT),
            KEY idx_egisz_client_snils (SNILS)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_egisz_staff (
            ID_USER INT UNSIGNED NOT NULL,
            SNILS VARCHAR(14) NULL DEFAULT NULL,
            POSITION_CODE VARCHAR(32) NULL DEFAULT NULL,
            SPECIALTY_CODE VARCHAR(32) NULL DEFAULT NULL,
            ROLE_CODE VARCHAR(32) NULL DEFAULT NULL,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID_USER),
            KEY idx_egisz_staff_snils (SNILS)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_egisz_outbox (
            ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            LOCAL_UID CHAR(36) NOT NULL,
            KIND VARCHAR(32) NOT NULL,
            ID_INSTANCE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_CLIENT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_EVENT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_DOCTOR INT UNSIGNED NOT NULL DEFAULT 0,
            STATUS VARCHAR(24) NOT NULL DEFAULT 'draft',
            SEMD_XML MEDIUMTEXT NULL,
            VALIDATION_JSON TEXT NULL,
            ERROR_TEXT TEXT NULL,
            OPERATOR VARCHAR(32) NOT NULL DEFAULT 'none',
            REMOTE_ID VARCHAR(128) NULL DEFAULT NULL,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_SENT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_egisz_outbox_uid (LOCAL_UID),
            KEY idx_egisz_outbox_status (STATUS),
            KEY idx_egisz_outbox_client (ID_CLIENT),
            KEY idx_egisz_outbox_instance (ID_INSTANCE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_egisz_icd10 (
            CODE VARCHAR(16) NOT NULL,
            NAME VARCHAR(512) NOT NULL,
            PARENT VARCHAR(16) NULL DEFAULT NULL,
            ENABLED TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (CODE),
            KEY idx_egisz_icd_name (NAME(191)),
            KEY idx_egisz_icd_parent (PARENT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
