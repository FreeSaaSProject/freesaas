<?php
declare(strict_types=1);

/**
 * Staff procedure restriction flags on base_users.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_users')) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $ensure = static function (string $col, string $ddl) use ($pdo, $st): void {
        $st->execute(['base_users', $col]);
        if ((int)$st->fetchColumn() > 0) {
            return;
        }
        $pdo->exec('ALTER TABLE base_users ADD COLUMN ' . $ddl);
        Database::clearSchemaCache();
    };

    $ensure('RESTRICT_TYPES', "`RESTRICT_TYPES` VARCHAR(1024) NOT NULL DEFAULT ''");
    $ensure('RESTRICT_TYPES_ONLINE', "`RESTRICT_TYPES_ONLINE` VARCHAR(1024) NOT NULL DEFAULT ''");
    // Nullable: TEXT/BLOB cannot have DEFAULT on many MySQL builds; create() writes ''.
    $ensure('RESTRICT_PROCS', '`RESTRICT_PROCS` MEDIUMTEXT NULL');
};
