<?php
declare(strict_types=1);

/**
 * Legacy base_records_collections_datas came without indexes (only PRIMARY): every lookup by
 * collection (collect DELETE, day/summary reports, calendar badge filter) was a full scan.
 */
return static function (PDO $pdo): void {
    $table = 'base_records_collections_datas';
    if (!Database::tableExists($table)) {
        return;
    }

    $hasLeading = static function (PDO $pdo, string $table, string $column): bool {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1
             LIMIT 1'
        );
        $st->execute([$table, $column]);
        return (bool)$st->fetchColumn();
    };

    if (!$hasLeading($pdo, $table, 'ID_COLLECTION')) {
        $pdo->exec('ALTER TABLE `' . $table . '` ADD INDEX `idx_cd_collection_part` (ID_COLLECTION, ID_PART)');
    }
    if (!$hasLeading($pdo, $table, 'ID_INSTANCE')) {
        $pdo->exec('ALTER TABLE `' . $table . '` ADD INDEX `idx_cd_instance` (ID_INSTANCE)');
    }
};
