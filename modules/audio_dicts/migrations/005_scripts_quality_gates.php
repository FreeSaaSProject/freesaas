<?php
declare(strict_types=1);

/**
 * Day quality gates on scripts:
 * root DAY_BONUS / DAY_BONUS_SUM / PROC_RATIO_MIN;
 * part GATE_RED_MAX / GATE_YELLOW_MAX (0+0 = not scoring).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_records_scripts')) {
        return;
    }
    $ensure = static function (PDO $pdo, string $col, string $ddl): void {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute(['base_records_scripts', $col]);
        if ((int)$st->fetchColumn() < 1) {
            $pdo->exec('ALTER TABLE `base_records_scripts` ADD COLUMN ' . $ddl);
        }
    };
    $ensure($pdo, 'DAY_BONUS', 'DAY_BONUS TINYINT(1) UNSIGNED NOT NULL DEFAULT 0');
    $ensure($pdo, 'DAY_BONUS_SUM', 'DAY_BONUS_SUM INT UNSIGNED NOT NULL DEFAULT 0');
    $ensure($pdo, 'PROC_RATIO_MIN', 'PROC_RATIO_MIN INT UNSIGNED NOT NULL DEFAULT 0');
    $ensure($pdo, 'GATE_RED_MAX', 'GATE_RED_MAX FLOAT NOT NULL DEFAULT 0');
    $ensure($pdo, 'GATE_YELLOW_MAX', 'GATE_YELLOW_MAX FLOAT NOT NULL DEFAULT 0');
};
