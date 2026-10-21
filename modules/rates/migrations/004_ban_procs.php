<?php
declare(strict_types=1);

/**
 * Per-product skill bans (within an enabled procedure type).
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $st->execute(['base_procent_history', 'ban_procs']);
    if ($st->fetchColumn()) {
        return;
    }
    $pdo->exec('ALTER TABLE base_procent_history ADD COLUMN ban_procs MEDIUMTEXT NULL');
};
