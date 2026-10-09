<?php
declare(strict_types=1);

/**
 * Staff notice preferences and common CRM columns missing from minimal greenfield base_users.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_users')) {
        return;
    }

    $cols = [
        'tel' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'favorites' => 'MEDIUMTEXT NULL',
        'showNewsAfter' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'IS_HIDDEN' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
    ];
    foreach ($cols as $name => $definition) {
        if (Database::columnExists('base_users', $name)) {
            continue;
        }
        $pdo->exec('ALTER TABLE `base_users` ADD COLUMN `' . $name . '` ' . $definition);
        Database::clearSchemaCache();
    }
};
