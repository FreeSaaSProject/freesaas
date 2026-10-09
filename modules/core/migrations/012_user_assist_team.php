<?php
declare(strict_types=1);

/**
 * Staff "assist team" flag used by workweeks/calendar shift ordering and assignable lists.
 * Name matches the hotfix already applied on some greenfield hosts (e.g. Jino).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_users') || Database::columnExists('base_users', 'ASSIST_TEAM')) {
        return;
    }
    $pdo->exec(
        'ALTER TABLE `base_users` ADD COLUMN `ASSIST_TEAM` TINYINT UNSIGNED NOT NULL DEFAULT 0'
    );
    Database::clearSchemaCache();
};
