<?php
declare(strict_types=1);

/**
 * Calendar-owned working hours columns on base_system (timeline axis).
 */
return static function (PDO $pdo): void {
    $has = $pdo->query("SHOW TABLES LIKE 'base_system'")->fetchColumn();
    if (!$has) {
        return;
    }
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM base_system')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $col) {
        $cols[(string)($col['Field'] ?? '')] = true;
    }
    if (empty($cols['work_hour_start'])) {
        $pdo->exec(
            'ALTER TABLE base_system ADD COLUMN work_hour_start TINYINT UNSIGNED NOT NULL DEFAULT 9'
        );
    }
    if (empty($cols['work_hour_end'])) {
        $pdo->exec(
            'ALTER TABLE base_system ADD COLUMN work_hour_end TINYINT UNSIGNED NOT NULL DEFAULT 22'
        );
    }
};
