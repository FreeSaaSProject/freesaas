<?php
declare(strict_types=1);

/**
 * Ensure base_users.IS_ACCEPTOR exists (contracts acceptor flag).
 */
return static function (PDO $pdo): void {
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $st->execute(['base_users', 'IS_ACCEPTOR']);
    if ($st->fetchColumn()) {
        return;
    }
    $pdo->exec(
        "ALTER TABLE base_users
         ADD COLUMN IS_ACCEPTOR TINYINT(1) NOT NULL DEFAULT 0
         COMMENT 'May accept contract offers'"
    );
};
