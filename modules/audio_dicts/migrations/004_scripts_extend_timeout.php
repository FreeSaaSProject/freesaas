<?php
declare(strict_types=1);

/**
 * Whether a part hit refreshes the open collection TIMEOUT.
 * Default 1 (legacy behaviour). Set 0 for mid parts like pings.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_records_scripts')) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['base_records_scripts', 'EXTEND_TIMEOUT']);
    if ((int)$st->fetchColumn() < 1) {
        $pdo->exec(
            'ALTER TABLE `base_records_scripts`
             ADD COLUMN EXTEND_TIMEOUT TINYINT UNSIGNED NOT NULL DEFAULT 1'
        );
    }
};
