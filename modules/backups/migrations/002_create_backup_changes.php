<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_backup_changes (
            ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            TBL VARCHAR(64) NOT NULL,
            PK_JSON VARCHAR(512) NOT NULL,
            OP CHAR(1) NOT NULL,
            TS DATETIME NOT NULL,
            PRIMARY KEY (ID),
            KEY idx_tbl_pk (TBL, PK_JSON(191)),
            KEY idx_ts (TS)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // KIND on runs: full | incr
    $has = false;
    try {
        $st = $pdo->query("SHOW COLUMNS FROM base_backup_runs LIKE 'KIND'");
        $has = $st && $st->fetch() !== false;
    } catch (Throwable $e) {
        $has = false;
    }
    if (!$has) {
        $pdo->exec(
            "ALTER TABLE base_backup_runs
             ADD COLUMN KIND VARCHAR(16) NOT NULL DEFAULT 'full' AFTER STATUS,
             ADD KEY idx_kind_started (KIND, STARTED_AT)"
        );
    }
};
