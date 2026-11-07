<?php
declare(strict_types=1);

/**
 * Staff birthday (oper parity): unix bday + show-in-calendar flag.
 * Oper DBs already have these columns — additive no-op when present.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_users')) {
        return;
    }

    $cols = [
        'bday' => 'INT NOT NULL DEFAULT 0',
        'show_bday_in_cal' => 'TINYINT(1) NOT NULL DEFAULT 1',
    ];
    foreach ($cols as $name => $definition) {
        if (Database::columnExists('base_users', $name)) {
            continue;
        }
        $pdo->exec('ALTER TABLE `base_users` ADD COLUMN `' . $name . '` ' . $definition);
        Database::clearSchemaCache();
    }
};
