<?php
declare(strict_types=1);

/**
 * Link undo rows to the spend they reverse (idempotent undo).
 */
return static function (PDO $pdo): void {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['base_certificate_ledger', 'ID_REF']);
    if ((int)$st->fetchColumn() < 1) {
        $pdo->exec(
            'ALTER TABLE `base_certificate_ledger`
             ADD COLUMN `ID_REF` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `ID_ORDER`,
             ADD KEY `idx_cert_ledger_ref` (`ID_REF`)'
        );
    }
};
