<?php
declare(strict_types=1);

/**
 * Oasis ticket flag on base_fullstats (0 unknown / 1 oasis / 2 not oasis).
 */
return static function (PDO $pdo): void {
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $st->execute(['base_fullstats', 'IS_OASIS']);
    if ($st->fetchColumn()) {
        return;
    }
    $pdo->exec(
        'ALTER TABLE base_fullstats
         ADD COLUMN IS_OASIS INT UNSIGNED NOT NULL DEFAULT 0 AFTER IS_PAYER,
         ADD KEY idx_is_oasis (IS_OASIS)'
    );
};
