<?php
declare(strict_types=1);

/**
 * Script rating engine: root RATING_ENABLED / IS_HIDDEN / LEGACY_TYPE_ID;
 * part SCORE_RATE (signed), KPI, COND_TYPE / COND_VALUE for metric parts.
 */
return static function (PDO $pdo): void {
    $ensureCol = static function (PDO $pdo, string $table, string $col, string $ddl): void {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$table, $col]);
        if ((int)$st->fetchColumn() < 1) {
            $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN ' . $ddl);
        }
    };

    if (!Database::tableExists('base_records_scripts')) {
        return;
    }

    // Root-level (PID=0) rating flags — columns exist on every row; parts ignore them.
    $ensureCol($pdo, 'base_records_scripts', 'RATING_ENABLED', 'RATING_ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_records_scripts', 'IS_HIDDEN', 'IS_HIDDEN TINYINT(1) UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_records_scripts', 'LEGACY_TYPE_ID', 'LEGACY_TYPE_ID INT UNSIGNED NOT NULL DEFAULT 0');

    // Part-level rating / metric condition fields.
    $ensureCol($pdo, 'base_records_scripts', 'SCORE_RATE', 'SCORE_RATE INT NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_records_scripts', 'KPI', 'KPI TINYINT(1) UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_records_scripts', 'COND_TYPE', "COND_TYPE VARCHAR(16) NOT NULL DEFAULT ''");
    $ensureCol($pdo, 'base_records_scripts', 'COND_VALUE', 'COND_VALUE INT NOT NULL DEFAULT 0');
};
