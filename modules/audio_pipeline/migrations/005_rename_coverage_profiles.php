<?php
declare(strict_types=1);

/**
 * Rename base_audio_coverage_profiles → base_records_coverage_profiles
 * (audio analytics tables use the base_records_* family).
 * Idempotent for installs that already applied 004 under either name.
 */
return static function (PDO $pdo): void {
    $new = 'base_records_coverage_profiles';
    $old = 'base_audio_coverage_profiles';

    if (Database::tableExists($old) && !Database::tableExists($new)) {
        $pdo->exec('RENAME TABLE `' . $old . '` TO `' . $new . '`');

        return;
    }

    if (Database::tableExists($old) && Database::tableExists($new)) {
        // Both present (unlikely): keep new, drop empty/legacy old after best-effort copy of missing rows.
        try {
            $pdo->exec(
                "INSERT IGNORE INTO `{$new}` (ID_POSITION, ID_ADMIN, ENABLED, CONFIG, DT_UPDATE)
                 SELECT ID_POSITION, ID_ADMIN, ENABLED, CONFIG, DT_UPDATE FROM `{$old}`"
            );
        } catch (Throwable $e) {
            // Unique conflicts / schema drift — drop legacy anyway.
        }
        $pdo->exec('DROP TABLE IF EXISTS `' . $old . '`');

        return;
    }

    if (!Database::tableExists($new)) {
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
    }
};
