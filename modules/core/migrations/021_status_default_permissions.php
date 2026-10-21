<?php
declare(strict_types=1);

/**
 * Default screen-permission pack per staff position (role).
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $st->execute(['base_admin_statuses', 'PERMISSIONS']);
    if ($st->fetchColumn()) {
        return;
    }
    $pdo->exec(
        'ALTER TABLE base_admin_statuses
         ADD COLUMN PERMISSIONS MEDIUMTEXT NULL
         COMMENT \'serialize permissions pack default for staff on this role\''
    );
};
