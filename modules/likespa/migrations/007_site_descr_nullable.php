<?php
declare(strict_types=1);

/**
 * Relax site_descr to NULL when an older 006 applied TEXT NOT NULL (no DEFAULT).
 * Strict SQL then blocked UsersRepository::create / virgin bootstrap.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_users') || !Database::columnExists('base_users', 'site_descr')) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['base_users', 'site_descr']);
    $nullable = strtoupper((string)$st->fetchColumn());
    if ($nullable === 'YES') {
        return;
    }
    $pdo->exec('ALTER TABLE base_users MODIFY COLUMN `site_descr` TEXT NULL');
    Database::clearSchemaCache();
};
