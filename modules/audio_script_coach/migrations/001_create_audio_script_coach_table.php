<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_script_coach_runs (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
            DAY DATE NOT NULL,
            STATUS TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PHASE VARCHAR(32) NOT NULL DEFAULT '',
            ERROR_TEXT VARCHAR(500) NOT NULL DEFAULT '',
            ID_CREATED_BY INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_admin_day (ID_ADMIN, DAY),
            KEY idx_status (STATUS)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_script_coach_artifacts (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_RUN INT UNSIGNED NOT NULL DEFAULT 0,
            PHASE VARCHAR(32) NOT NULL DEFAULT '',
            PAYLOAD_JSON MEDIUMTEXT NOT NULL,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_run_phase (ID_RUN, PHASE),
            KEY idx_run (ID_RUN)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_script_coach_proposals (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_RUN INT UNSIGNED NOT NULL DEFAULT 0,
            ID_SCRIPT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_PART INT UNSIGNED NOT NULL DEFAULT 0,
            ACTION VARCHAR(32) NOT NULL DEFAULT '',
            ID_DICT INT UNSIGNED NOT NULL DEFAULT 0,
            NEW_DICT_NAME VARCHAR(100) NOT NULL DEFAULT '',
            PHRASES_JSON TEXT NOT NULL,
            RATIONALE VARCHAR(500) NOT NULL DEFAULT '',
            CONFIDENCE DECIMAL(4,3) NOT NULL DEFAULT 0,
            LINK_PART TINYINT UNSIGNED NOT NULL DEFAULT 0,
            STATUS TINYINT UNSIGNED NOT NULL DEFAULT 0,
            ID_DECIDED_BY INT UNSIGNED NOT NULL DEFAULT 0,
            DT_DECIDE INT UNSIGNED NOT NULL DEFAULT 0,
            APPLY_META_JSON TEXT NULL,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_run_status (ID_RUN, STATUS),
            KEY idx_script (ID_SCRIPT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
