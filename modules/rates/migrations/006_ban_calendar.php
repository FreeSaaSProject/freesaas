<?php
declare(strict_types=1);

/**
 * Per-type «hide details in calendar (admins)» flags: serialized map type_id => 1.
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $st->execute(['base_procent_history', 'ban_calendar']);
    if ($st->fetchColumn()) {
        return;
    }
    $pdo->exec('ALTER TABLE base_procent_history ADD COLUMN ban_calendar MEDIUMTEXT NULL');
};
