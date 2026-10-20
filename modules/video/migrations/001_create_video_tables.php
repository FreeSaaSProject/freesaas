<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_video_workers (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            WORKER_KEY VARCHAR(64) NOT NULL,
            HOSTNAME VARCHAR(255) NOT NULL DEFAULT '',
            LAST_SEEN INT UNSIGNED NOT NULL DEFAULT 0,
            META_JSON MEDIUMTEXT NULL,
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_worker_key (WORKER_KEY)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_video_models (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            WEIGHTS_PATH VARCHAR(512) NOT NULL DEFAULT '',
            CLASSES_JSON MEDIUMTEXT NULL,
            NOTES VARCHAR(512) NOT NULL DEFAULT '',
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            CREATED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_enabled (ENABLED)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_video_cameras (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            RTSP_URL VARCHAR(1024) NOT NULL DEFAULT '',
            MODE VARCHAR(32) NOT NULL DEFAULT 'monitoring',
            MODEL_ID INT UNSIGNED NOT NULL DEFAULT 0,
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            CONF_THRESHOLD DECIMAL(5,3) NOT NULL DEFAULT 0.250,
            IOU_THRESHOLD DECIMAL(5,3) NOT NULL DEFAULT 0.450,
            INTERVAL_SEC INT UNSIGNED NOT NULL DEFAULT 30,
            SETTINGS_JSON MEDIUMTEXT NULL,
            LAST_FRAME_PATH VARCHAR(512) NOT NULL DEFAULT '',
            LAST_ANNOTATED_PATH VARCHAR(512) NOT NULL DEFAULT '',
            LAST_DETECT_JSON MEDIUMTEXT NULL,
            LAST_SEEN INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_enabled (ENABLED),
            KEY idx_model (MODEL_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_video_jobs (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            TYPE VARCHAR(64) NOT NULL DEFAULT '',
            STATUS VARCHAR(32) NOT NULL DEFAULT 'pending',
            PAYLOAD_JSON MEDIUMTEXT NULL,
            RESULT_JSON MEDIUMTEXT NULL,
            CLAIMED_BY VARCHAR(64) NOT NULL DEFAULT '',
            CLAIMED_UNTIL INT UNSIGNED NOT NULL DEFAULT 0,
            ERROR_TEXT VARCHAR(1024) NOT NULL DEFAULT '',
            CREATED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            UPDATED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_status_type (STATUS, TYPE),
            KEY idx_claimed (CLAIMED_UNTIL)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_video_alerts (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            CAMERA_ID INT UNSIGNED NOT NULL DEFAULT 0,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            KIND VARCHAR(64) NOT NULL DEFAULT 'absence',
            MESSAGE VARCHAR(1024) NOT NULL DEFAULT '',
            FRAME_PATHS_JSON MEDIUMTEXT NULL,
            META_JSON MEDIUMTEXT NULL,
            NOTIFIED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_camera_dt (CAMERA_ID, DT),
            KEY idx_dt (DT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_video_detections (
            ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            CAMERA_ID INT UNSIGNED NOT NULL DEFAULT 0,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            FRAME_PATH VARCHAR(512) NOT NULL DEFAULT '',
            DETECTIONS_JSON MEDIUMTEXT NULL,
            ALERT_ID INT UNSIGNED NOT NULL DEFAULT 0,
            BYTES INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_camera_dt (CAMERA_ID, DT),
            KEY idx_alert (ALERT_ID),
            KEY idx_dt (DT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
