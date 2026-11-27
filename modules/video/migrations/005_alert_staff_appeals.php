<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Alert attribution (STAFF_ID = on-duty person) + state, appeals table.
 */
return static function (PDO $pdo): void {
    if (Database::tableExists('base_video_alerts')) {
        $cols = [
            'STAFF_ID' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            // 0 = active, 1 = cancelled (appeal accepted)
            'STATE' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
        ];
        foreach ($cols as $name => $definition) {
            if (!Database::columnExists('base_video_alerts', $name)) {
                $pdo->exec('ALTER TABLE `base_video_alerts` ADD COLUMN `' . $name . '` ' . $definition);
                Database::clearSchemaCache();
            }
        }
        $idx = $pdo->query("SHOW INDEX FROM `base_video_alerts` WHERE Key_name = 'idx_video_alerts_staff'")->fetchAll();
        if ($idx === []) {
            $pdo->exec('ALTER TABLE `base_video_alerts` ADD KEY `idx_video_alerts_staff` (`STAFF_ID`, `DT`)');
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_video_appeals (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ALERT_ID INT UNSIGNED NOT NULL DEFAULT 0,
            STAFF_ID INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            REASON VARCHAR(1024) NOT NULL DEFAULT '',
            STATUS TINYINT UNSIGNED NOT NULL DEFAULT 0,
            DECIDER_ID INT UNSIGNED NOT NULL DEFAULT 0,
            DT_DECISION INT UNSIGNED NOT NULL DEFAULT 0,
            COMMENT VARCHAR(1024) NOT NULL DEFAULT '',
            PRIMARY KEY (ID),
            UNIQUE KEY uq_video_appeal_alert (ALERT_ID),
            KEY idx_video_appeal_status (STATUS, DT_CREATE),
            KEY idx_video_appeal_staff (STAFF_ID, DT_CREATE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
