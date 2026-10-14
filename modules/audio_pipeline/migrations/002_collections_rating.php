<?php
declare(strict_types=1);

/**
 * Collection rating fields + indexes for rating aggregates.
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

    $ensureIndex = static function (PDO $pdo, string $table, string $name, string $ddl): void {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
        );
        $st->execute([$table, $name]);
        if ((bool)$st->fetchColumn()) {
            return;
        }
        try {
            $pdo->exec(
                'ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD INDEX `'
                . str_replace('`', '``', $name) . '` ' . $ddl
            );
        } catch (Throwable $e) {
            // Similar index may already exist under another name.
        }
    };

    if (Database::tableExists('base_records_collections')) {
        $ensureCol($pdo, 'base_records_collections', 'SCORE_RATE', 'SCORE_RATE INT NOT NULL DEFAULT 0');
        $ensureCol($pdo, 'base_records_collections', 'RATED', 'RATED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0');
        $ensureCol($pdo, 'base_records_collections', 'GATE_REASON', "GATE_REASON VARCHAR(32) NOT NULL DEFAULT ''");
        $ensureIndex($pdo, 'base_records_collections', 'idx_coll_admin_dt', '(ID_ADMIN, DT_START)');
        $ensureIndex($pdo, 'base_records_collections', 'idx_coll_rated_admin', '(RATED, ID_ADMIN, DT_START)');
    }

    if (Database::tableExists('base_records_instances')) {
        $ensureIndex($pdo, 'base_records_instances', 'idx_inst_record', '(ID_RECORD)');
        $ensureIndex($pdo, 'base_records_instances', 'idx_inst_admin_dt', '(ID_ADMIN, DT_START)');
    }
};
