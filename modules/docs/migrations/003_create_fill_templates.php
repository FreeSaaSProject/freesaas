<?php
declare(strict_types=1);

/**
 * Clinic (ID_ADMIN=0) and per-doctor default phrases for visit-sheet fields.
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_doc_fill_templates (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_DEF INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
            FIELDS_JSON JSON NULL,
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_doc_fill_def_admin (ID_DEF, ID_ADMIN),
            KEY idx_doc_fill_admin (ID_ADMIN)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
