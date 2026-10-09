<?php
declare(strict_types=1);

/**
 * Explicit FOT role on move types (0=none, 1=bank, 2=cash). Replaces ini name-match.
 */
return static function (PDO $pdo): void {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['base_account_move_types', 'FOT_KIND']);
    if ((int)$st->fetchColumn() < 1) {
        $pdo->exec(
            'ALTER TABLE base_account_move_types
             ADD COLUMN FOT_KIND TINYINT UNSIGNED NOT NULL DEFAULT 0
             AFTER ID_PAYMENT_ENABLED'
        );
    }

    $idx = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $idx->execute(['base_account_move_types', 'idx_amt_fot_kind']);
    if ((int)$idx->fetchColumn() < 1) {
        $pdo->exec('ALTER TABLE base_account_move_types ADD INDEX idx_amt_fot_kind (FOT_KIND)');
    }

    // One-time seed from legacy ini names (if still present).
    $bankName = '';
    $cashName = '';
    if (class_exists('Config', false)) {
        $bankName = trim((string)Config::get('ledger', 'fot_bank_type', 'ФОТ'));
        $cashName = trim((string)Config::get('ledger', 'fot_cash_type', 'ФОТ Нал'));
    }
    if ($bankName === '') {
        $bankName = 'ФОТ';
    }
    if ($cashName === '') {
        $cashName = 'ФОТ Нал';
    }
    $upd = $pdo->prepare(
        'UPDATE base_account_move_types SET FOT_KIND = ?
         WHERE NAME = ? AND FOT_KIND = 0 AND ENABLED > 0'
    );
    $upd->execute([1, $bankName]);
    $upd->execute([2, $cashName]);
};
