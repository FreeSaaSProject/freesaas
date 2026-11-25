<?php
declare(strict_types=1);

/**
 * Accumulating funds: accrual rules, ledger type → fund map, rule/period/source tags on movements.
 * Additive only (CREATE IF NOT EXISTS, ALTER guarded by information_schema probes).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_fund_rules (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_FUND INT UNSIGNED NOT NULL DEFAULT 0,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            KIND TINYINT UNSIGNED NOT NULL DEFAULT 1,
            PERCENT DOUBLE NOT NULL DEFAULT 0,
            FIXED_SUM DOUBLE NOT NULL DEFAULT 0,
            PERIOD TINYINT UNSIGNED NOT NULL DEFAULT 1,
            BASE_MODE TINYINT UNSIGNED NOT NULL DEFAULT 1,
            BASE_TYPES VARCHAR(500) NOT NULL DEFAULT '',
            DT_FROM INT UNSIGNED NOT NULL DEFAULT 0,
            DT_TO INT UNSIGNED NOT NULL DEFAULT 0,
            ENABLED TINYINT UNSIGNED NOT NULL DEFAULT 1,
            NOTE MEDIUMTEXT NULL,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_USER_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_fund_rules_fund (ID_FUND)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_fund_type_map (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_TYPE INT UNSIGNED NOT NULL DEFAULT 0,
            ID_FUND INT UNSIGNED NOT NULL DEFAULT 0,
            ENABLED TINYINT UNSIGNED NOT NULL DEFAULT 1,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_fund_type_map_type (ID_TYPE),
            KEY idx_fund_type_map_fund (ID_FUND)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $probe = static function (string $table, string $column) use ($pdo): bool {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$table, $column]);
        return (int)$st->fetchColumn() > 0;
    };
    $indexProbe = static function (string $table, string $index) use ($pdo): bool {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $st->execute([$table, $index]);
        return (int)$st->fetchColumn() > 0;
    };

    if (!$probe('base_fund_movements', 'RULE_ID')) {
        $pdo->exec('ALTER TABLE base_fund_movements ADD COLUMN RULE_ID INT UNSIGNED NOT NULL DEFAULT 0');
    }
    if (!$probe('base_fund_movements', 'PERIOD_KEY')) {
        $pdo->exec("ALTER TABLE base_fund_movements ADD COLUMN PERIOD_KEY VARCHAR(16) NOT NULL DEFAULT ''");
    }
    if (!$probe('base_fund_movements', 'SRC')) {
        // 0 manual, 1 accrual rule, 2 auto from ledger DPS, 3 fund transfer
        $pdo->exec('ALTER TABLE base_fund_movements ADD COLUMN SRC TINYINT UNSIGNED NOT NULL DEFAULT 0');
    }
    if (!$indexProbe('base_fund_movements', 'idx_fm_rule_period')) {
        $pdo->exec('ALTER TABLE base_fund_movements ADD KEY idx_fm_rule_period (RULE_ID, PERIOD_KEY)');
    }
};
