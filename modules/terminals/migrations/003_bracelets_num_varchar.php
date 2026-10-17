<?php
declare(strict_types=1);

/**
 * Locker bracelet NUM: allow alphanumeric codes (e.g. V019), not only integers.
 */
return static function (PDO $pdo): void {
    $st = $pdo->prepare(
        'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $st->execute(['base_bracelets', 'NUM']);
    $col = $st->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        return;
    }
    $type = strtolower((string)($col['DATA_TYPE'] ?? ''));
    $len = (int)($col['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
    if ($type === 'varchar' && $len >= 16) {
        return;
    }
    $pdo->exec(
        "ALTER TABLE base_bracelets
         MODIFY COLUMN NUM VARCHAR(16) NOT NULL DEFAULT ''"
    );
};
