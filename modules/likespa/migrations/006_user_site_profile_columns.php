<?php
declare(strict_types=1);

/**
 * Client-site / tips fields on base_users (likespa staff profile for public site).
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

    $ensure('link_ok', "`link_ok` VARCHAR(512) NOT NULL DEFAULT ''");
    $ensure('photo', "`photo` VARCHAR(512) NOT NULL DEFAULT ''");
    $ensure('site_descr', "`site_descr` TEXT NOT NULL");
};
