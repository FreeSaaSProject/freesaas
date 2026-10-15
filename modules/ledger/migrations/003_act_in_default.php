<?php
declare(strict_types=1);

/**
 * Legacy base_account_moves.ID_ACT_IN may lack DEFAULT; strict SQL then rejects INSERT.
 */
return static function (PDO $pdo): void {
    $st = $pdo->prepare(
        'SELECT COLUMN_DEFAULT, IS_NULLABLE, COLUMN_TYPE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['base_account_moves', 'ID_ACT_IN']);
    $col = $st->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec(
            'ALTER TABLE base_account_moves
             ADD COLUMN ID_ACT_IN INT UNSIGNED NOT NULL DEFAULT 0 AFTER ID_TYPE'
        );
        return;
    }
    if ($col['COLUMN_DEFAULT'] === null || $col['COLUMN_DEFAULT'] === '') {
        $pdo->exec(
            'ALTER TABLE base_account_moves
             MODIFY COLUMN ID_ACT_IN INT UNSIGNED NOT NULL DEFAULT 0'
        );
    }
};
