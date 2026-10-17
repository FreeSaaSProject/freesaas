<?php
declare(strict_types=1);

/**
 * Therm / terminal registry + occupancy analytics (oper patches 387–490).
 * Column IS_MASSЕUR_MODE keeps Cyrillic Е to match shared legacy DB.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_terminals (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_OPERATOR_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ENABLED TINYINT UNSIGNED NOT NULL DEFAULT 1,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            CODE VARCHAR(32) NOT NULL DEFAULT '',
            ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
            DT_ADMIN_VALID INT UNSIGNED NOT NULL DEFAULT 0,
            ID_EXECUTOR INT UNSIGNED NOT NULL DEFAULT 0,
            DT_EXECUTOR_FREE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER_LOCKED INT UNSIGNED NOT NULL DEFAULT 0,
            IS_ADMIN_REG_IGNORE TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `IS_MASSЕUR_MODE` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            IS_TERM_ADMIN_MODE TINYINT UNSIGNED NOT NULL DEFAULT 0,
            EXECUTORS VARCHAR(255) NOT NULL DEFAULT '',
            GOODS VARCHAR(255) NOT NULL DEFAULT '',
            DT_EXECUTOR_START INT UNSIGNED NOT NULL DEFAULT 0,
            DT_EXECUTOR_END INT UNSIGNED NOT NULL DEFAULT 0,
            IS_EMPLOYEE_PROCEDURE_NOTIFY TINYINT UNSIGNED NOT NULL DEFAULT 1,
            IS_BRACELET_NUM_PROMPT TINYINT UNSIGNED NOT NULL DEFAULT 0,
            IS_TERMINAL_REVIEW_INVITE TINYINT UNSIGNED NOT NULL DEFAULT 0,
            IS_TERMINAL_SHOW_DAY TINYINT UNSIGNED NOT NULL DEFAULT 0,
            IS_TERMINAL_FLYER_DISCOUNT TINYINT UNSIGNED NOT NULL DEFAULT 0,
            IS_TERMINAL_CLIENT_HISTORY TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_code (CODE),
            KEY idx_enabled (ENABLED)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensureCol = static function (PDO $pdo, string $table, string $column, string $ddl): void {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $st->execute([$table, $column]);
        if ($st->fetchColumn()) {
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
    };

    $ensureCol($pdo, 'base_terminals', 'ID_ADMIN', 'ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_terminals', 'DT_ADMIN_VALID', 'DT_ADMIN_VALID INT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_terminals', 'ID_EXECUTOR', 'ID_EXECUTOR INT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_terminals', 'DT_EXECUTOR_FREE', 'DT_EXECUTOR_FREE INT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_terminals', 'ID_USER_LOCKED', 'ID_USER_LOCKED INT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_terminals', 'IS_ADMIN_REG_IGNORE', 'IS_ADMIN_REG_IGNORE TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_terminals', 'IS_MASSЕUR_MODE', '`IS_MASSЕUR_MODE` TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_terminals', 'IS_TERM_ADMIN_MODE', 'IS_TERM_ADMIN_MODE TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_terminals', 'EXECUTORS', "EXECUTORS VARCHAR(255) NOT NULL DEFAULT ''");
    $ensureCol($pdo, 'base_terminals', 'GOODS', "GOODS VARCHAR(255) NOT NULL DEFAULT ''");
    $ensureCol($pdo, 'base_terminals', 'DT_EXECUTOR_START', 'DT_EXECUTOR_START INT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_terminals', 'DT_EXECUTOR_END', 'DT_EXECUTOR_END INT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_terminals', 'IS_EMPLOYEE_PROCEDURE_NOTIFY', 'IS_EMPLOYEE_PROCEDURE_NOTIFY TINYINT UNSIGNED NOT NULL DEFAULT 1');
    $ensureCol($pdo, 'base_terminals', 'IS_BRACELET_NUM_PROMPT', 'IS_BRACELET_NUM_PROMPT TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_terminals', 'IS_TERMINAL_REVIEW_INVITE', 'IS_TERMINAL_REVIEW_INVITE TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_terminals', 'IS_TERMINAL_SHOW_DAY', 'IS_TERMINAL_SHOW_DAY TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_terminals', 'IS_TERMINAL_FLYER_DISCOUNT', 'IS_TERMINAL_FLYER_DISCOUNT TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $ensureCol($pdo, 'base_terminals', 'IS_TERMINAL_CLIENT_HISTORY', 'IS_TERMINAL_CLIENT_HISTORY TINYINT UNSIGNED NOT NULL DEFAULT 0');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_bracelets (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT INT UNSIGNED NOT NULL DEFAULT 0,
            UID VARCHAR(20) NOT NULL DEFAULT '',
            UID2 VARCHAR(20) NOT NULL DEFAULT '',
            NUM VARCHAR(16) NOT NULL DEFAULT '',
            PRIMARY KEY (ID),
            UNIQUE KEY UID (UID),
            UNIQUE KEY UID2 (UID2)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ensureCol($pdo, 'base_bracelets', 'NUM', "NUM VARCHAR(16) NOT NULL DEFAULT ''");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_termloads (
            ID INT UNSIGNED NOT NULL,
            VALUE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_fullstats (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DTCREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DTSTART INT UNSIGNED NOT NULL DEFAULT 0,
            DTEND INT UNSIGNED NOT NULL DEFAULT 0,
            DURATION INT UNSIGNED NOT NULL DEFAULT 0,
            ID1C_USER VARCHAR(60) NOT NULL DEFAULT '',
            TITLE_FULL VARCHAR(255) NOT NULL DEFAULT '',
            TICKET_TYPE VARCHAR(255) NOT NULL DEFAULT '',
            ID_TYPE INT UNSIGNED NOT NULL DEFAULT 0,
            IS_LIMITED INT UNSIGNED NOT NULL DEFAULT 0,
            IS_PAYER INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_dtstart (DTSTART),
            KEY idx_ticket_type (TICKET_TYPE(64))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_term_orders (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_VISIT INT UNSIGNED NOT NULL DEFAULT 0,
            DAYS_BEFORE INT UNSIGNED NOT NULL DEFAULT 0,
            HOUR_VISIT INT UNSIGNED NOT NULL DEFAULT 0,
            PHONE VARCHAR(11) NOT NULL DEFAULT '',
            ADULTS INT UNSIGNED NOT NULL DEFAULT 0,
            CHILDREN INT UNSIGNED NOT NULL DEFAULT 0,
            ELDERS INT UNSIGNED NOT NULL DEFAULT 0,
            TOTAL INT UNSIGNED NOT NULL DEFAULT 0,
            SERT_SUMM INT UNSIGNED NOT NULL DEFAULT 0,
            TOTAL_SUMM INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_dt_create (DT_CREATE),
            KEY idx_dt_visit (DT_VISIT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_terminals_activations (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_ADMIN INT UNSIGNED NOT NULL DEFAULT 0,
            ID_TERMINAL INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_terminal (ID_TERMINAL),
            KEY idx_admin (ID_ADMIN)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
