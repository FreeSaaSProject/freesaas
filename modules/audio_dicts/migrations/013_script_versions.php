<?php
declare(strict_types=1);

/**
 * Immutable published script trees for as-of collect / day reports.
 * Working copy stays in base_records_scripts; collect reads versions, not the draft.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_records_script_versions (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_SCRIPT INT UNSIGNED NOT NULL DEFAULT 0,
            VERSION INT UNSIGNED NOT NULL DEFAULT 1,
            VALID_FROM INT UNSIGNED NOT NULL DEFAULT 0,
            STATUS VARCHAR(16) NOT NULL DEFAULT 'active',
            TREE_JSON MEDIUMTEXT NULL,
            TREE_HASH CHAR(64) NOT NULL DEFAULT '',
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_script_version (ID_SCRIPT, VERSION),
            KEY idx_script_asof (ID_SCRIPT, STATUS, VALID_FROM, ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!Database::tableExists('base_records_scripts')) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['base_records_scripts', 'ID_VERSION_CURRENT']);
    if ((int)$st->fetchColumn() < 1) {
        $pdo->exec(
            'ALTER TABLE `base_records_scripts`
             ADD COLUMN ID_VERSION_CURRENT INT UNSIGNED NOT NULL DEFAULT 0'
        );
    }
};
