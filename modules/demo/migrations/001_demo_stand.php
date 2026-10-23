<?php
declare(strict_types=1);

/**
 * Demo stand marker (single row). Seed/wipe refuse to run without a matching row,
 * so demo flags copied onto a production config cannot wipe real data.
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_demo_stand (
            ID TINYINT UNSIGNED NOT NULL DEFAULT 1,
            STAND_ID VARCHAR(64) NOT NULL DEFAULT '',
            LOCALE VARCHAR(8) NOT NULL DEFAULT '',
            SEED_VERSION VARCHAR(32) NOT NULL DEFAULT '',
            SEEDED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            CLAIMED_AT INT UNSIGNED NOT NULL DEFAULT 0,
            DUMPS MEDIUMTEXT NULL,
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
};
