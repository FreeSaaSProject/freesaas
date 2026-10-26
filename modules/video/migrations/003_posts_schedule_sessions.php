<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_video_posts (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            MODEL_ID INT UNSIGNED NOT NULL DEFAULT 0,
            SETTINGS_JSON MEDIUMTEXT NULL,
            CREATED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            UPDATED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_enabled (ENABLED)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $cols = $pdo->query("SHOW COLUMNS FROM base_video_cameras LIKE 'POST_ID'")->fetchAll();
    if ($cols === []) {
        $pdo->exec(
            'ALTER TABLE base_video_cameras
             ADD COLUMN POST_ID INT UNSIGNED NOT NULL DEFAULT 0 AFTER ID,
             ADD KEY idx_post (POST_ID)'
        );
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_video_train_sessions (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            POST_ID INT UNSIGNED NOT NULL DEFAULT 0,
            CAMERA_ID INT UNSIGNED NOT NULL DEFAULT 0,
            PROJECT_ID INT UNSIGNED NOT NULL DEFAULT 0,
            PHASE VARCHAR(32) NOT NULL DEFAULT 'solo_class',
            TARGET_CLASS_ID INT NOT NULL DEFAULT 0,
            SOURCE_MODEL VARCHAR(64) NOT NULL DEFAULT 'stock_yolo',
            PERSON_CLASS VARCHAR(64) NOT NULL DEFAULT 'person',
            INTERVAL_MS INT UNSIGNED NOT NULL DEFAULT 500,
            STATUS VARCHAR(32) NOT NULL DEFAULT 'running',
            COVERAGE_JSON MEDIUMTEXT NULL,
            STATS_JSON MEDIUMTEXT NULL,
            LAST_FRAME_PATH VARCHAR(512) NOT NULL DEFAULT '',
            LAST_ANNOTATED_PATH VARCHAR(512) NOT NULL DEFAULT '',
            CREATED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            UPDATED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_status (STATUS),
            KEY idx_camera (CAMERA_ID),
            KEY idx_post (POST_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
