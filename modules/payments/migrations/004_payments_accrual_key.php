<?php
declare(strict_types=1);

/**
 * Unique accrual identity for day/month payroll sync (race-safe upserts).
 * ACCRUAL_KEY is NULL for ordinary payments; UNIQUE allows many NULLs.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_payments')) {
        return;
    }

    if (!Database::columnExists('base_payments', 'ACCRUAL_KEY')) {
        $pdo->exec(
            'ALTER TABLE base_payments
             ADD COLUMN `ACCRUAL_KEY` VARCHAR(64) NULL DEFAULT NULL'
        );
    }

    // Backfill day accruals: "Начисление ЗП ФЛ j.m.Y"
    $st = $pdo->query(
        "SELECT ID, ID_USER, DESCR FROM base_payments
         WHERE ACCRUAL_KEY IS NULL
           AND CAST(DIRECTION AS SIGNED) > 0
           AND DESCR LIKE 'Начисление ЗП ФЛ %'"
    );
    $upd = $pdo->prepare('UPDATE base_payments SET ACCRUAL_KEY = ? WHERE ID = ? AND ACCRUAL_KEY IS NULL');
    foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
        $descr = (string)($row['DESCR'] ?? '');
        if (!preg_match('/^Начисление ЗП ФЛ\s+(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $descr, $m)) {
            continue;
        }
        $key = sprintf('day:%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        $upd->execute([$key, (int)$row['ID']]);
    }

    // Backfill month accruals: "% MONTH YYYY"
    $months = [
        'ЯНВАРЬ' => 1, 'ФЕВРАЛЬ' => 2, 'МАРТ' => 3, 'АПРЕЛЬ' => 4,
        'МАЙ' => 5, 'ИЮНЬ' => 6, 'ИЮЛЬ' => 7, 'АВГУСТ' => 8,
        'СЕНТЯБРЬ' => 9, 'ОКТЯБРЬ' => 10, 'НОЯБРЬ' => 11, 'ДЕКАБРЬ' => 12,
    ];
    $st = $pdo->query(
        "SELECT ID, DESCR FROM base_payments
         WHERE ACCRUAL_KEY IS NULL
           AND CAST(DIRECTION AS SIGNED) > 0
           AND ACCOUNT = 0
           AND DESCR LIKE '% %'"
    );
    foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
        $descr = trim((string)($row['DESCR'] ?? ''));
        if (!preg_match('/^%\s+(\S+)\s+(\d{4})$/u', $descr, $m)) {
            continue;
        }
        $mon = $months[$m[1]] ?? 0;
        if ($mon < 1) {
            continue;
        }
        $key = sprintf('month:%04d-%02d', (int)$m[2], $mon);
        $upd->execute([$key, (int)$row['ID']]);
    }

    // Drop older duplicates so UNIQUE can be added (keep newest ID).
    $dup = $pdo->query(
        'SELECT ID_USER, ACCRUAL_KEY, MAX(ID) AS keep_id, COUNT(*) AS cnt
         FROM base_payments
         WHERE ACCRUAL_KEY IS NOT NULL AND ACCRUAL_KEY <> \'\'
         GROUP BY ID_USER, ACCRUAL_KEY
         HAVING cnt > 1'
    );
    $del = $pdo->prepare('DELETE FROM base_payments WHERE ID_USER = ? AND ACCRUAL_KEY = ? AND ID <> ?');
    foreach ($dup ? ($dup->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $g) {
        $del->execute([(int)$g['ID_USER'], (string)$g['ACCRUAL_KEY'], (int)$g['keep_id']]);
    }

    $idx = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $idx->execute(['base_payments', 'uq_payments_user_accrual']);
    if ((int)$idx->fetchColumn() < 1) {
        $pdo->exec(
            'ALTER TABLE base_payments
             ADD UNIQUE KEY uq_payments_user_accrual (ID_USER, ACCRUAL_KEY)'
        );
    }
};
