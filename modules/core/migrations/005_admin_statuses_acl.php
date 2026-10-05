<?php
declare(strict_types=1);

/**
 * Minimal ACL role table for greenfield installs (oper may already provide a richer schema).
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_admin_statuses (
            ID INT NOT NULL AUTO_INCREMENT,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            ENABLED TINYINT(1) NOT NULL DEFAULT 1,
            ASSIST_STA TINYINT(1) NOT NULL DEFAULT 0,
            PARTNER_STA TINYINT(1) NOT NULL DEFAULT 0,
            SUPERADMIN_ACCESS TINYINT(1) NOT NULL DEFAULT 0,
            CONTROL_ACCESS TINYINT(1) NOT NULL DEFAULT 0,
            SHOW_ADMINISTRATOR_DATA TINYINT(1) NOT NULL DEFAULT 0,
            CAN_SEE_SUM_OTHERS TINYINT(1) NOT NULL DEFAULT 0,
            SHOW_NEWS TINYINT(1) NOT NULL DEFAULT 1,
            FASTAUTH_WORKTIME_ONLY TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Additive flags if table already existed from an older schema
    $flags = [
        'ENABLED', 'ASSIST_STA', 'PARTNER_STA', 'SUPERADMIN_ACCESS', 'CONTROL_ACCESS',
        'SHOW_ADMINISTRATOR_DATA', 'CAN_SEE_SUM_OTHERS', 'SHOW_NEWS', 'FASTAUTH_WORKTIME_ONLY',
    ];
    foreach ($flags as $col) {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $st->execute(['base_admin_statuses', $col]);
        if ($st->fetchColumn()) {
            continue;
        }
        $pdo->exec('ALTER TABLE base_admin_statuses ADD COLUMN `' . $col . '` TINYINT(1) NOT NULL DEFAULT 0');
    }
};
