<?php
declare(strict_types=1);

/**
 * Longer pending draft previews (who/what will change before Confirm).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_assistant_pending')) {
        return;
    }
    $st = $pdo->query(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'base_assistant_pending'
           AND COLUMN_NAME = 'SUMMARY'
         LIMIT 1"
    );
    $type = $st ? strtolower((string)$st->fetchColumn()) : '';
    if ($type === 'mediumtext' || $type === 'text' || $type === 'longtext') {
        return;
    }
    $pdo->exec(
        'ALTER TABLE base_assistant_pending
         MODIFY SUMMARY MEDIUMTEXT NOT NULL'
    );
};
