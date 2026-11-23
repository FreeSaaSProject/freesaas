<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    if (!Database::tableExists('base_procedures')) {
        return;
    }
    $st = $pdo->query(
        "SELECT DATA_TYPE AS dt, CHARACTER_MAXIMUM_LENGTH AS len
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'base_procedures'
           AND COLUMN_NAME = 'NOTICE'
         LIMIT 1"
    );
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if (!is_array($row)) {
        return;
    }
    $dt = strtolower((string)($row['dt'] ?? ''));
    $len = (int)($row['len'] ?? 0);
    if ($dt === 'text' || $dt === 'mediumtext' || $dt === 'longtext' || $len >= 2000) {
        return;
    }
    $pdo->exec(
        'ALTER TABLE `base_procedures`
         MODIFY COLUMN `NOTICE` VARCHAR(2000) NOT NULL DEFAULT \'\''
    );
};
