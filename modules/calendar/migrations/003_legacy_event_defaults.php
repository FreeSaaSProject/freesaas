<?php
declare(strict_types=1);

/**
 * Legacy oper dumps have NOT NULL event columns without DEFAULT (DTENABLE etc.).
 * Ensure they exist and accept inserts without explicit values.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_events')) {
        return;
    }

    $adds = [
        'DTENABLE' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'OPERATOR_ENABLED' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'UPSALE_DATA' => "MEDIUMTEXT NOT NULL",
        'CONTENT_DATA' => "MEDIUMTEXT NOT NULL",
        'NONEWEXPL' => "VARCHAR(255) NOT NULL DEFAULT ''",
    ];
    foreach ($adds as $col => $def) {
        if (!Database::columnExists('base_events', $col)) {
            $pdo->exec('ALTER TABLE `base_events` ADD COLUMN `' . $col . '` ' . $def);
        }
    }

    // Harden existing columns that lack DEFAULT (strict SQL mode → 1364).
    $defaults = [
        'DTENABLE' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'OPERATOR_ENABLED' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'NONEWEXPL' => "VARCHAR(255) NOT NULL DEFAULT ''",
    ];
    foreach ($defaults as $col => $def) {
        if (Database::columnExists('base_events', $col)) {
            $pdo->exec('ALTER TABLE `base_events` MODIFY COLUMN `' . $col . '` ' . $def);
        }
    }

    // TEXT/MEDIUMTEXT cannot have non-null default on older MySQL — keep empty via insert path.
    foreach (['UPSALE_DATA', 'CONTENT_DATA'] as $col) {
        if (!Database::columnExists('base_events', $col)) {
            continue;
        }
        // Backfill NULLs if any slipped in.
        $pdo->exec('UPDATE `base_events` SET `' . $col . '` = \'\' WHERE `' . $col . '` IS NULL');
    }
};
