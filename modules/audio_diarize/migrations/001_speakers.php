<?php
declare(strict_types=1);

/**
 * Cabin speaker diarization + voiceprint profiles (owned by audio_diarize).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_speakers (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_RECORD INT UNSIGNED NOT NULL DEFAULT 0,
            LOCAL_ID VARCHAR(16) NOT NULL DEFAULT '',
            ROLE VARCHAR(16) NOT NULL DEFAULT 'unknown',
            MATCHED_ADMIN_ID INT UNSIGNED NOT NULL DEFAULT 0,
            CONFIDENCE FLOAT NOT NULL DEFAULT 0,
            TALK_SEC FLOAT NOT NULL DEFAULT 0,
            IS_DOMINANT TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            DAY_CLUSTER VARCHAR(32) NOT NULL DEFAULT '',
            EMBEDDING MEDIUMBLOB NULL,
            EMBEDDING_DIM SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_record_local (ID_RECORD, LOCAL_ID),
            KEY ID_RECORD (ID_RECORD),
            KEY MATCHED_ADMIN_ID (MATCHED_ADMIN_ID),
            KEY DAY_CLUSTER (DAY_CLUSTER)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_speaker_segments (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_RECORD INT UNSIGNED NOT NULL DEFAULT 0,
            LOCAL_ID VARCHAR(16) NOT NULL DEFAULT '',
            T0 FLOAT NOT NULL DEFAULT 0,
            T1 FLOAT NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY ID_RECORD (ID_RECORD),
            KEY rec_local (ID_RECORD, LOCAL_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_cue_speakers (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_RECORD INT UNSIGNED NOT NULL DEFAULT 0,
            STRING_NUM INT UNSIGNED NOT NULL DEFAULT 0,
            LOCAL_ID VARCHAR(16) NOT NULL DEFAULT '',
            PRIMARY KEY (ID),
            UNIQUE KEY uq_rec_line (ID_RECORD, STRING_NUM),
            KEY ID_RECORD (ID_RECORD)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_speaker_profiles (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
            EMBEDDING MEDIUMBLOB NULL,
            EMBEDDING_DIM SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            SAMPLE_COUNT INT UNSIGNED NOT NULL DEFAULT 0,
            TALK_SEC_TOTAL FLOAT NOT NULL DEFAULT 0,
            QUALITY TINYINT UNSIGNED NOT NULL DEFAULT 0,
            SOURCE VARCHAR(32) NOT NULL DEFAULT 'auto',
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_admin (ID_ADMIN),
            KEY DT_UPDATE (DT_UPDATE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
