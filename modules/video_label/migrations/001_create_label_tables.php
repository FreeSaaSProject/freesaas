<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_video_label_projects (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            CLASSES_JSON MEDIUMTEXT NULL,
            SETTINGS_JSON MEDIUMTEXT NULL,
            MODEL_ID INT UNSIGNED NOT NULL DEFAULT 0,
            CONF_THRESHOLD DECIMAL(5,3) NOT NULL DEFAULT 0.250,
            IOU_THRESHOLD DECIMAL(5,3) NOT NULL DEFAULT 0.450,
            CREATED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_video_label_images (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            PROJECT_ID INT UNSIGNED NOT NULL DEFAULT 0,
            FILENAME VARCHAR(255) NOT NULL DEFAULT '',
            PATH VARCHAR(512) NOT NULL DEFAULT '',
            SPLIT VARCHAR(16) NOT NULL DEFAULT 'train',
            MARKED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            LABELS_JSON MEDIUMTEXT NULL,
            PREDICTIONS_JSON MEDIUMTEXT NULL,
            UPDATED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_project (PROJECT_ID),
            KEY idx_split (PROJECT_ID, SPLIT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
