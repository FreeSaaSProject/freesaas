<?php
declare(strict_types=1);

/**
 * Root DAY_GATES JSON (list of day-quality metric gates).
 * Backfill from PROC_RATIO_MIN when present.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_records_scripts')) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['base_records_scripts', 'DAY_GATES']);
    if ((int)$st->fetchColumn() < 1) {
        $pdo->exec(
            "ALTER TABLE `base_records_scripts`
             ADD COLUMN DAY_GATES TEXT NULL"
        );
    }

    if (!Database::columnExists('base_records_scripts', 'PROC_RATIO_MIN')) {
        return;
    }
    $rows = $pdo->query(
        'SELECT ID AS id, PROC_RATIO_MIN AS proc_ratio_min, DAY_GATES AS day_gates
         FROM base_records_scripts
         WHERE PID = 0 AND PROC_RATIO_MIN > 0'
    );
    if (!$rows) {
        return;
    }
    $upd = $pdo->prepare('UPDATE base_records_scripts SET DAY_GATES = ? WHERE ID = ? AND PID = 0');
    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $raw = trim((string)($row['day_gates'] ?? ''));
        if ($raw !== '' && $raw !== '[]' && $raw !== 'null') {
            continue;
        }
        $min = (int)($row['proc_ratio_min'] ?? 0);
        if ($min < 1) {
            continue;
        }
        $json = json_encode([
            [
                'metric' => 'proc_ratio',
                'op' => 'gte',
                'threshold' => $min,
            ],
        ], JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            continue;
        }
        $upd->execute([$json, (int)$row['id']]);
    }
};
