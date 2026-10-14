<?php
declare(strict_types=1);

/**
 * Per-status / per-staff coverage & procedure-match overrides.
 * ID_ADMIN>0 = staff override; else ID_POSITION = role profile.
 */
return static function (PDO $pdo): void {
    if (Database::tableExists('base_records_coverage_profiles')) {
        return;
    }
    // Legacy name from early drafts — rename instead of recreating.
    if (Database::tableExists('base_audio_coverage_profiles')) {
        $pdo->exec('RENAME TABLE `base_audio_coverage_profiles` TO `base_records_coverage_profiles`');

        return;
    }
    $pdo->exec(
        'CREATE TABLE `base_records_coverage_profiles` (
            `ID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ID_POSITION` INT UNSIGNED NOT NULL DEFAULT 0,
            `ID_ADMIN` INT UNSIGNED NOT NULL DEFAULT 0,
            `ENABLED` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `CONFIG` TEXT NULL,
            `DT_UPDATE` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`ID`),
            UNIQUE KEY `uq_coverage_scope` (`ID_POSITION`, `ID_ADMIN`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
};
