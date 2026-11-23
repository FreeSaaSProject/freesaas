<?php
declare(strict_types=1);

/**
 * Ensure additive source columns (idempotent). Needed when 001 ran before these ALTERs existed.
 */
return static function (PDO $pdo): void {
    $ensureCol = static function (PDO $pdo, string $table, string $col, string $ddl): void {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$table, $col]);
        if ((int)$st->fetchColumn() < 1) {
            $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN ' . $ddl);
        }
    };

    $stTbl = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_clients'"
    );
    if ($stTbl && (int)$stTbl->fetchColumn() > 0) {
        $ensureCol($pdo, 'base_clients', 'source_id', 'source_id INT UNSIGNED NOT NULL DEFAULT 0');
        $ensureCol($pdo, 'base_clients', 'source_descr', "source_descr VARCHAR(255) NOT NULL DEFAULT ''");
        $ensureCol($pdo, 'base_clients', 'referred_by_client_id', 'referred_by_client_id INT UNSIGNED NOT NULL DEFAULT 0');
    }

    $stMoves = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_account_moves'"
    );
    if ($stMoves && (int)$stMoves->fetchColumn() > 0) {
        $ensureCol($pdo, 'base_account_moves', 'ID_SOURCE', 'ID_SOURCE INT UNSIGNED NOT NULL DEFAULT 0');
    }
};
