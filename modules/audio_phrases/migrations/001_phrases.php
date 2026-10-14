<?php
declare(strict_types=1);

/**
 * Stable phrase catalog + per-day stats (recordTopper port).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_records_phrases')) {
        $pdo->exec(
            "CREATE TABLE base_records_phrases (
                ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
                HASH CHAR(40) NOT NULL,
                LEMMAS VARCHAR(512) NOT NULL DEFAULT '',
                WORDS TINYINT UNSIGNED NOT NULL DEFAULT 0,
                EXAMPLE VARCHAR(1024) NOT NULL DEFAULT '',
                STATUS VARCHAR(16) NOT NULL DEFAULT 'new',
                ID_DICTONARY INT UNSIGNED NOT NULL DEFAULT 0,
                ID_KEY INT UNSIGNED NOT NULL DEFAULT 0,
                DT_FIRST_SEEN INT UNSIGNED NOT NULL DEFAULT 0,
                DT_LAST_SEEN INT UNSIGNED NOT NULL DEFAULT 0,
                DAYS_SEEN INT UNSIGNED NOT NULL DEFAULT 0,
                ADMINS_SEEN INT UNSIGNED NOT NULL DEFAULT 0,
                TOTAL_COUNT INT UNSIGNED NOT NULL DEFAULT 0,
                DT_STATUS INT UNSIGNED NOT NULL DEFAULT 0,
                ID_USER_STATUS INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (ID),
                UNIQUE KEY uq_phrase_hash (HASH),
                KEY idx_phrase_status (STATUS, DT_LAST_SEEN),
                KEY idx_phrase_dict (ID_DICTONARY),
                KEY idx_phrase_key (ID_KEY)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    if (!Database::tableExists('base_records_phrase_days')) {
        $pdo->exec(
            "CREATE TABLE base_records_phrase_days (
                ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
                ID_PHRASE INT UNSIGNED NOT NULL,
                ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
                DAY DATE NOT NULL,
                COUNT INT UNSIGNED NOT NULL DEFAULT 0,
                RECORDS_COUNT INT UNSIGNED NOT NULL DEFAULT 0,
                EXAMPLES TEXT NULL,
                PRIMARY KEY (ID),
                UNIQUE KEY uq_phrase_day_admin (ID_PHRASE, ID_ADMIN, DAY),
                KEY idx_phrase_day (DAY, ID_ADMIN),
                KEY idx_phrase_day_phrase (ID_PHRASE, DAY)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
};
