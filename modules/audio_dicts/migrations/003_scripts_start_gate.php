<?php
declare(strict_types=1);

/**
 * Dual-start gates on script parts:
 * 0 = normal, 1 = primary start (AND group), 2 = alt start (OR alone).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_records_scripts')) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['base_records_scripts', 'START_GATE']);
    if ((int)$st->fetchColumn() < 1) {
        $pdo->exec(
            'ALTER TABLE `base_records_scripts`
             ADD COLUMN START_GATE TINYINT UNSIGNED NOT NULL DEFAULT 0'
        );
    }
};
