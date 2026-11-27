<?php
declare(strict_types=1);

/**
 * Records disabled because of a video absence alert (so an accepted appeal restores exactly those).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_audio_video_effects (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ALERT_ID INT UNSIGNED NOT NULL DEFAULT 0,
            RECORD_ID INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_alert_record (ALERT_ID, RECORD_ID),
            KEY idx_record (RECORD_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
