<?php
declare(strict_types=1);

/**
 * Ensure legacy IS_MASSЕUR_MODE (Cyrillic Е) has DEFAULT 0.
 * Light/mysqldump restores sometimes drop the default; CDC REPLACE that omit
 * the column then fail under STRICT_TRANS_TABLES on standby.
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    // Column name deliberately contains Cyrillic Е (U+0415), not Latin E.
    $col = 'IS_MASSЕUR_MODE';
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['base_terminals', $col]);
    if ((int)$st->fetchColumn() < 1) {
        return;
    }
    $pdo->exec(
        'ALTER TABLE `base_terminals`
         MODIFY `' . str_replace('`', '``', $col) . '` TINYINT UNSIGNED NOT NULL DEFAULT 0'
    );
};
