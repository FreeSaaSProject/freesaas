<?php
declare(strict_types=1);

/**
 * One-shot bridge for instances that already ran the old 001 (with types).
 * After both live instances migrate, delete this file from the repo.
 */
return static function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS base_payment_types');

    $dropCol = static function (PDO $pdo, string $table, string $col): void {
        if (!Database::columnExists($table, $col)) {
            return;
        }
        $pdo->exec('ALTER TABLE `' . $table . '` DROP COLUMN `' . $col . '`');
    };
    $dropCol($pdo, 'base_payments', 'ID_TYPE');
    $dropCol($pdo, 'base_payments', 'ID_TYPE_TO');
};
