<?php
declare(strict_types=1);

/**
 * Per-type booking / online bans on rate profiles (skill SoT alongside %).
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    $ensure = static function (PDO $pdo, string $col, string $ddl): void {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $st->execute(['base_procent_history', $col]);
        if ($st->fetchColumn()) {
            return;
        }
        $pdo->exec('ALTER TABLE base_procent_history ADD COLUMN ' . $ddl);
    };

    $ensure($pdo, 'ban_booking', 'ban_booking MEDIUMTEXT NULL');
    $ensure($pdo, 'ban_online', 'ban_online MEDIUMTEXT NULL');
};
