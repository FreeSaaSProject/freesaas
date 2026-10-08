<?php
declare(strict_types=1);

/**
 * Ensure base_news access / sign / archive columns and base_views exist
 * (oper ACCESS_GROUP*, ACCESS_USER, SIGN_REQ, IGNORE_FOLDER; views for read/sign).
 */
return static function (PDO $pdo): void {
    $colExists = static function (string $table, string $col) use ($pdo): bool {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $st->execute([$table, $col]);
        return (bool)$st->fetchColumn();
    };
    $tableExists = static function (string $table) use ($pdo): bool {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    };

    if (!$tableExists('base_news')) {
        return;
    }

    $cols = [
        'ACCESS_GROUP' => "VARCHAR(255) NOT NULL DEFAULT '' AFTER BIG",
        'ACCESS_GROUP2' => "VARCHAR(255) NOT NULL DEFAULT '' AFTER ACCESS_GROUP",
        'ACCESS_USER' => "VARCHAR(255) NOT NULL DEFAULT '' AFTER ACCESS_GROUP2",
        'SIGN_REQ' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
        'USERS_SIGNED' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'IGNORE_FOLDER' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
        'HAS_CHILDREN' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
    ];
    foreach ($cols as $name => $def) {
        if ($colExists('base_news', $name)) {
            continue;
        }
        $pdo->exec('ALTER TABLE base_news ADD COLUMN `' . $name . '` ' . $def);
    }

    if (!$tableExists('base_views')) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS base_views (
                ID INT NOT NULL AUTO_INCREMENT,
                DT INT NOT NULL DEFAULT 0,
                ID_USER INT NOT NULL DEFAULT 0,
                ID_OBJ INT NOT NULL DEFAULT 0,
                TYPE_OBJ VARCHAR(255) NOT NULL DEFAULT \'\',
                PRIMARY KEY (ID),
                KEY idx_views_lookup (TYPE_OBJ, ID_USER, ID_OBJ)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
};
