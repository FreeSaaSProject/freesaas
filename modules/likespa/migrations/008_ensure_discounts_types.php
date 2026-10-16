<?php
declare(strict_types=1);

/**
 * Per-type max discount caps on base_system (oper DISCOUNTS_TYPES / show_site_sets).
 */
return static function (PDO $pdo): void {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['base_system', 'DISCOUNTS_TYPES']);
    if ((int)$st->fetchColumn() > 0) {
        return;
    }
    $pdo->exec('ALTER TABLE base_system ADD COLUMN `DISCOUNTS_TYPES` TEXT NOT NULL');
};
