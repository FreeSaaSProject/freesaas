<?php
declare(strict_types=1);

/**
 * Journal for explicit ffprobe PLAYTIME/VOLUME re-probe (agent/LongJob only).
 * Not used by normal ASR / asr_dia pipelines. Anti-loop: terminal done|failed
 * blocks auto re-queue even when PLAYTIME stays 0.
 */
return static function (PDO $pdo): void {
    // Pre-ship rename (never on prod): base_media_probe_runs → base_records_probe_runs
    $st = $pdo->query("SHOW TABLES LIKE 'base_media_probe_runs'");
    $old = $st !== false && (bool)$st->fetchColumn();
    $st = $pdo->query("SHOW TABLES LIKE 'base_records_probe_runs'");
    $neu = $st !== false && (bool)$st->fetchColumn();
    if ($old && !$neu) {
        $pdo->exec('RENAME TABLE base_media_probe_runs TO base_records_probe_runs');
        return;
    }
    if ($old && $neu) {
        $pdo->exec('DROP TABLE base_media_probe_runs');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_probe_runs (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_RECORD INT UNSIGNED NOT NULL DEFAULT 0,
            JOB_ID CHAR(32) NOT NULL DEFAULT '',
            STATUS VARCHAR(16) NOT NULL DEFAULT 'pending',
            FORCE_OVERWRITE TINYINT NOT NULL DEFAULT 1,
            PLAYTIME INT NOT NULL DEFAULT 0,
            VOLUME DECIMAL(12,4) NOT NULL DEFAULT 0,
            ERROR VARCHAR(500) NOT NULL DEFAULT '',
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_DONE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY ID_RECORD (ID_RECORD),
            KEY JOB_ID (JOB_ID),
            KEY STATUS (STATUS),
            KEY DT_CREATE (DT_CREATE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
};
