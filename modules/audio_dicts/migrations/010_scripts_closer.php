<?php
declare(strict_types=1);

/**
 * Closer role: flush+close open collection (distinct from conversion FINISH and BREAK).
 */
return static function (PDO $pdo): void {
    if (!class_exists('Database', false) || !Database::tableExists('base_records_scripts')) {
        return;
    }
    if (!Database::columnExists('base_records_scripts', 'CLOSER')) {
        $pdo->exec(
            'ALTER TABLE base_records_scripts
             ADD COLUMN CLOSER TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER FINISH'
        );
    }
    // Legacy FINISH meant «end of session» in practice — promote to closer, keep FINISH.
    if (Database::columnExists('base_records_scripts', 'FINISH')) {
        $pdo->exec('UPDATE base_records_scripts SET CLOSER = 1 WHERE FINISH = 1 AND CLOSER = 0 AND PID > 0');
    }
};
