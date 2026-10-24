<?php
declare(strict_types=1);

/**
 * Min idle seconds after mid-content before a BREAK part may reopen the collection.
 * Default 120. 0 = immediate reopen after mid (legacy). Ignored while collection
 * still has only START_GATE parts (start-phase coalesce).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_records_scripts')) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['base_records_scripts', 'BREAK_GAP']);
    if ((int)$st->fetchColumn() < 1) {
        $pdo->exec(
            'ALTER TABLE `base_records_scripts`
             ADD COLUMN BREAK_GAP INT UNSIGNED NOT NULL DEFAULT 120'
        );
    }
};
