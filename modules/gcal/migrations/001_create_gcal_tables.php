<?php
declare(strict_types=1);

/**
 * Google Calendar sync for staff my-tasks (TYPE=2).
 * Renamed from oper employee_bot_* tables.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS base_gcal_tokens (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            ACTIVE_CALENDAR_ID VARCHAR(255) NOT NULL DEFAULT \'\',
            DONE_CALENDAR_ID VARCHAR(255) NOT NULL DEFAULT \'\',
            ACCESS_TOKEN TEXT NOT NULL,
            REFRESH_TOKEN TEXT NOT NULL,
            EXPIRES_AT INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uniq_user (ID_USER),
            KEY idx_expires (EXPIRES_AT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS base_gcal_task_links (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_TASK INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            ACTIVE_CALENDAR_ID VARCHAR(255) NOT NULL DEFAULT \'\',
            DONE_CALENDAR_ID VARCHAR(255) NOT NULL DEFAULT \'\',
            EVENT_ID VARCHAR(255) NOT NULL DEFAULT \'\',
            EVENT_ETAG VARCHAR(255) NOT NULL DEFAULT \'\',
            SLOT_INDEX INT NOT NULL DEFAULT 0,
            SYNC_HASH VARCHAR(64) NOT NULL DEFAULT \'\',
            LAST_SYNC_DIR VARCHAR(16) NOT NULL DEFAULT \'none\',
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uniq_task_user (ID_TASK, ID_USER),
            KEY idx_task (ID_TASK),
            KEY idx_event (EVENT_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS base_gcal_sync_queue (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_TASK INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            REASON VARCHAR(64) NOT NULL DEFAULT \'update\',
            FORCE_SYNC TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            STATUS TINYINT UNSIGNED NOT NULL DEFAULT 0,
            ATTEMPTS INT UNSIGNED NOT NULL DEFAULT 0,
            LAST_ERROR TEXT NOT NULL,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_status_create (STATUS, DT_CREATE),
            KEY idx_task (ID_TASK)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    // Best-effort copy from legacy oper table names on the same DB.
    $legacy = [
        'employee_bot_google_tokens' => 'base_gcal_tokens',
        'employee_bot_task_calendar_links' => 'base_gcal_task_links',
        'employee_bot_task_sync_queue' => 'base_gcal_sync_queue',
    ];
    foreach ($legacy as $from => $to) {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $st->execute([$from]);
        if (!$st->fetchColumn()) {
            continue;
        }
        $cnt = (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $to) . '`')->fetchColumn();
        if ($cnt > 0) {
            continue;
        }
        try {
            $pdo->exec('INSERT INTO `' . str_replace('`', '``', $to) . '` SELECT * FROM `' . str_replace('`', '``', $from) . '`');
        } catch (Throwable $e) {
            // Column mismatch — ignore; settings UI still works for new links.
        }
    }
};
