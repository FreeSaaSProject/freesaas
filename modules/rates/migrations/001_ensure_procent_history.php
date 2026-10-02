<?php
declare(strict_types=1);

/**
 * Specialist pay rates. Shared with legacy oper — keep uppercase column names.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_procent_history (
            ID INT NOT NULL AUTO_INCREMENT,
            ID_OPERATOR INT NOT NULL DEFAULT 0,
            DT INT NOT NULL DEFAULT 0,
            DESCR VARCHAR(255) NOT NULL DEFAULT '',
            content MEDIUMTEXT NULL,
            content_sales MEDIUMTEXT NULL,
            LOSS DOUBLE NOT NULL DEFAULT 0,
            content_income MEDIUMTEXT NULL,
            LOSS_FIX DOUBLE NOT NULL DEFAULT 0,
            content_terminal MEDIUMTEXT NULL,
            OFFERS INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY idx_procent_operator_dt (ID_OPERATOR, DT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensure = static function (PDO $pdo, string $col, string $ddl): void {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute(['base_procent_history', $col]);
        if ((int)$st->fetchColumn() < 1) {
            $pdo->exec('ALTER TABLE base_procent_history ADD COLUMN ' . $ddl);
        }
    };

    $ensure($pdo, 'content_terminal', 'content_terminal MEDIUMTEXT NULL');
    $ensure($pdo, 'OFFERS', 'OFFERS INT UNSIGNED NOT NULL DEFAULT 0');
    $ensure($pdo, 'LOSS_FIX', 'LOSS_FIX DOUBLE NOT NULL DEFAULT 0');
};
