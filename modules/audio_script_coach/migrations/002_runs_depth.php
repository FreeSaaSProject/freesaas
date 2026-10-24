<?php
declare(strict_types=1);

/**
 * Enrich depth: normal (gaps only) | hard (stage fill even on covered days).
 */
return static function (PDO $pdo): void {
    if (!class_exists('Database', false) || !Database::tableExists('base_script_coach_runs')) {
        return;
    }
    if (!Database::columnExists('base_script_coach_runs', 'DEPTH')) {
        $pdo->exec(
            "ALTER TABLE base_script_coach_runs
             ADD COLUMN DEPTH VARCHAR(16) NOT NULL DEFAULT 'normal' AFTER PHASE"
        );
    }
};
