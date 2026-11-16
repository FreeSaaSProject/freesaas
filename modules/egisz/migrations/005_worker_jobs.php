<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Edge worker job queue (N3 SOAP runs on Mac/VPS with OpenVPN, not on shared hosting).
 */

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_egisz_worker_jobs (
            ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            TYPE VARCHAR(32) NOT NULL,
            OUTBOX_ID BIGINT UNSIGNED NOT NULL DEFAULT 0,
            STATUS VARCHAR(16) NOT NULL DEFAULT 'pending',
            PAYLOAD_JSON MEDIUMTEXT NULL,
            RESULT_JSON MEDIUMTEXT NULL,
            ERROR_TEXT TEXT NULL,
            CLAIMED_BY VARCHAR(64) NOT NULL DEFAULT '',
            CLAIMED_UNTIL INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_egisz_wjobs_status (STATUS, ID),
            KEY idx_egisz_wjobs_outbox (OUTBOX_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
