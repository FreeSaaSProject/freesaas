<?php
declare(strict_types=1);

/**
 * Many payments ↔ one ledger move (multi-allocation). One payment links to at most one move.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_payment_move_links (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ID_PAYMENT INT UNSIGNED NOT NULL DEFAULT 0,
            ID_MOVE INT UNSIGNED NOT NULL DEFAULT 0,
            SUMM DOUBLE NOT NULL DEFAULT 0,
            DT_CREATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            UNIQUE KEY uq_pml_payment (ID_PAYMENT),
            KEY idx_pml_move (ID_MOVE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute(['base_account_moves']);
    if ((int)$st->fetchColumn() < 1) {
        return;
    }

    // Backfill from legacy single ID_PAYMENT on moves.
    $rows = $pdo->query(
        'SELECT ID AS move_id, ID_PAYMENT AS payment_id, SUMM AS summ
         FROM base_account_moves
         WHERE ID_PAYMENT > 0 AND DELETED = 0'
    );
    if (!$rows) {
        return;
    }
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO base_payment_move_links (ID_PAYMENT, ID_MOVE, SUMM, DT_CREATE)
         VALUES (?, ?, ?, ?)'
    );
    $now = time();
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pid = (int)($row['payment_id'] ?? 0);
        $mid = (int)($row['move_id'] ?? 0);
        if ($pid < 1 || $mid < 1) {
            continue;
        }
        $sum = 0.0;
        $pst = $pdo->prepare('SELECT ABS(SUM) FROM base_payments WHERE ID = ? LIMIT 1');
        $pst->execute([$pid]);
        $sum = (float)$pst->fetchColumn();
        if ($sum <= 0) {
            $sum = abs((float)($row['summ'] ?? 0));
        }
        $ins->execute([$pid, $mid, $sum, $now]);
    }
};
