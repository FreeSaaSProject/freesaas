<?php
declare(strict_types=1);

/**
 * Pin which published script version produced a collection (NULL = pre-versioning).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_records_collections')) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['base_records_collections', 'ID_SCRIPT_VERSION']);
    if ((int)$st->fetchColumn() < 1) {
        $pdo->exec(
            'ALTER TABLE `base_records_collections`
             ADD COLUMN ID_SCRIPT_VERSION INT UNSIGNED NULL DEFAULT NULL'
        );
    }
};
