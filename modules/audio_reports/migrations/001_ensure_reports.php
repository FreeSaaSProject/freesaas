<?php
declare(strict_types=1);

/**
 * Summary report profiles + day-bound columns on base_system.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_summarys (
            ID INT NOT NULL AUTO_INCREMENT,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            ID_POSITION INT NOT NULL DEFAULT 0,
            CONFIG_JSON TEXT NOT NULL,
            LABELS_JSON TEXT NOT NULL,
            IS_DEFAULT TINYINT(1) NOT NULL DEFAULT 0,
            ENABLED TINYINT(1) NOT NULL DEFAULT 1,
            ID_CREATOR INT NOT NULL DEFAULT 0,
            DT_CREATE INT NOT NULL DEFAULT 0,
            DT_UPDATE INT NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_enabled_default (ENABLED, IS_DEFAULT),
            KEY idx_position (ID_POSITION)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $has = $pdo->query("SHOW TABLES LIKE 'base_system'")->fetchColumn();
    if (!$has) {
        return;
    }

    $ensureCol = static function (PDO $pdo, string $col, string $ddl): void {
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM base_system')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cols[(string)($row['Field'] ?? '')] = true;
        }
        if (empty($cols[$col])) {
            $pdo->exec('ALTER TABLE base_system ADD COLUMN ' . $ddl);
        }
    };

    // Legacy oper day bounds for rating / recording windows
    $ensureCol($pdo, 'hourStartOffRate', 'hourStartOffRate TINYINT UNSIGNED NOT NULL DEFAULT 9');
    $ensureCol($pdo, 'minStartOffRate', 'minStartOffRate TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'hourEndOffRate', 'hourEndOffRate TINYINT UNSIGNED NOT NULL DEFAULT 22');
    $ensureCol($pdo, 'minEndOffRate', 'minEndOffRate TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'hourStartOffRecord', 'hourStartOffRecord TINYINT UNSIGNED NOT NULL DEFAULT 9');
    $ensureCol($pdo, 'minStartOffRecord', 'minStartOffRecord TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'hourEndOffRecord', 'hourEndOffRecord TINYINT UNSIGNED NOT NULL DEFAULT 22');
    $ensureCol($pdo, 'minEndOffRecord', 'minEndOffRecord TINYINT UNSIGNED NOT NULL DEFAULT 0');
};
