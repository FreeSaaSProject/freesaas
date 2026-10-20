<?php
declare(strict_types=1);

/**
 * Flyer + promoter discount columns on base_system.
 */
return static function (PDO $pdo): void {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $ensure = static function (string $col, string $ddl) use ($pdo, $st): void {
        $st->execute(['base_system', $col]);
        if ((int)$st->fetchColumn() > 0) {
            return;
        }
        $pdo->exec('ALTER TABLE base_system ADD COLUMN ' . $ddl);
    };

    $ensure('flyerEnabled', '`flyerEnabled` TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $ensure('flyerDiscount', '`flyerDiscount` TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $ensure('PROMO_POSITION_ID', "`PROMO_POSITION_ID` VARCHAR(255) NOT NULL DEFAULT ''");
    $ensure('PROMO_DISCOUNTS', '`PROMO_DISCOUNTS` TEXT NOT NULL');
};
