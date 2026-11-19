<?php
declare(strict_types=1);

/**
 * Experiment journal for dia-first asr_dia pipeline (side-by-side vs cue diarize).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_asr_dia_runs (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_RECORD INT UNSIGNED NOT NULL DEFAULT 0,
            JOB_ID CHAR(32) NOT NULL DEFAULT '',
            ENGINE VARCHAR(32) NOT NULL DEFAULT 'asr_dia',
            ASR_MODEL VARCHAR(64) NOT NULL DEFAULT '',
            STATUS VARCHAR(16) NOT NULL DEFAULT 'pending',
            APPLY_SPEAKERS TINYINT NOT NULL DEFAULT 1,
            APPLY_TRANSCRIPTION TINYINT NOT NULL DEFAULT 0,
            CUES INT UNSIGNED NOT NULL DEFAULT 0,
            MATCHED_CUES INT UNSIGNED NOT NULL DEFAULT 0,
            MATCHED_PCT DECIMAL(6,2) NOT NULL DEFAULT 0,
            ERROR VARCHAR(500) NOT NULL DEFAULT '',
            META_JSON MEDIUMTEXT NULL,
            TRANSCRIPTION_NEW MEDIUMTEXT NULL,
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
