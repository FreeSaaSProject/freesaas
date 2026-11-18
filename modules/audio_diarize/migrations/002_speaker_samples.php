<?php
declare(strict_types=1);

/**
 * Manual voice enrollment clips for staff profiles.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_speaker_samples (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
            ID_RECORD INT UNSIGNED NOT NULL DEFAULT 0,
            T0 FLOAT NOT NULL DEFAULT 0,
            T1 FLOAT NOT NULL DEFAULT 0,
            SOURCE VARCHAR(16) NOT NULL DEFAULT 'manual',
            EMBEDDING MEDIUMBLOB NULL,
            EMBEDDING_DIM SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            DT_EMBEDDED INT UNSIGNED NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY ID_ADMIN (ID_ADMIN),
            KEY ID_RECORD (ID_RECORD),
            KEY SOURCE (SOURCE),
            KEY pending_embed (ID_ADMIN, DT_EMBEDDED, SOURCE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
