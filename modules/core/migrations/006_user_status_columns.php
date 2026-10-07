<?php
declare(strict_types=1);

/**
 * Rename legacy staff status FKs on base_users:
 * name_enabled → ID_STATUS, STATUS2 → ID_STATUS2.
 * Uses CHANGE (MySQL 5.7 compatible; RENAME COLUMN needs 8.0+).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_users')) {
        return;
    }

    $has = static function (string $col): bool {
        return Database::columnExists('base_users', $col);
    };

    $change = static function (string $from, string $to) use ($pdo): void {
        $st = $pdo->prepare(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
             LIMIT 1'
        );
        $st->execute(['base_users', $from]);
        $col = $st->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            return;
        }
        $type = (string)$col['COLUMN_TYPE'];
        $null = strtoupper((string)$col['IS_NULLABLE']) === 'YES' ? 'NULL' : 'NOT NULL';
        $extra = trim((string)($col['EXTRA'] ?? ''));
        $defaultSql = '';
        if (array_key_exists('COLUMN_DEFAULT', $col) && $col['COLUMN_DEFAULT'] !== null) {
            $defaultSql = ' DEFAULT ' . $pdo->quote((string)$col['COLUMN_DEFAULT']);
        } elseif ($null === 'NOT NULL') {
            $defaultSql = ' DEFAULT 0';
        }
        $sql = 'ALTER TABLE `base_users` CHANGE `' . $from . '` `' . $to . '` '
            . $type . ' ' . $null . $defaultSql;
        if ($extra !== '') {
            $sql .= ' ' . $extra;
        }
        $pdo->exec($sql);
        Database::clearSchemaCache();
    };

    if ($has('name_enabled') && !$has('ID_STATUS')) {
        $change('name_enabled', 'ID_STATUS');
    } elseif (!$has('ID_STATUS')) {
        $pdo->exec('ALTER TABLE `base_users` ADD COLUMN `ID_STATUS` INT NOT NULL DEFAULT 0');
        Database::clearSchemaCache();
    }

    if ($has('STATUS2') && !$has('ID_STATUS2')) {
        $change('STATUS2', 'ID_STATUS2');
    } elseif (!$has('ID_STATUS2')) {
        $pdo->exec('ALTER TABLE `base_users` ADD COLUMN `ID_STATUS2` INT NOT NULL DEFAULT 0');
        Database::clearSchemaCache();
    }
};
