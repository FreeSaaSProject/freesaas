<?php
declare(strict_types=1);

/**
 * Drop unused legacy ID_TYPE from base_records_keys (product uses ID_DICTONARY only).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_records_keys')) {
        return;
    }
    if (!Database::columnExists('base_records_keys', 'ID_TYPE')) {
        return;
    }
    try {
        $pdo->exec('ALTER TABLE `base_records_keys` DROP INDEX `ID_TYPE`');
    } catch (Throwable $e) {
        // Index may be absent or named differently.
    }
    $pdo->exec('ALTER TABLE `base_records_keys` DROP COLUMN `ID_TYPE`');
};
