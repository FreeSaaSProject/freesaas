<?php
declare(strict_types=1);

/**
 * Backfill IS_OASIS: default "not oasis"; only Sep–Nov 2026 visits with
 * oasis in the name get IS_OASIS=1. Add indexes for classify scans.
 */
return static function (PDO $pdo): void {
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $st->execute(['base_fullstats', 'IS_OASIS']);
    if (!$st->fetchColumn()) {
        return;
    }

    // Sep–Nov 2026 inclusive → [2026-09-01, 2026-12-01).
    $from = (int)strtotime('2026-09-01 00:00:00');
    $toExcl = (int)strtotime('2026-12-01 00:00:00');
    if ($from < 1) {
        $from = 1756684800;
    }
    if ($toExcl < 1) {
        $toExcl = 1764547200;
    }

    // Everyone → not oasis.
    $pdo->exec('UPDATE base_fullstats SET IS_OASIS = 2 WHERE IS_OASIS <> 2');

    // Sep–Nov + name contains oasis → yes.
    $upd = $pdo->prepare(
        'UPDATE base_fullstats SET IS_OASIS = 1
         WHERE DTSTART >= ? AND DTSTART < ?
           AND (
             TICKET_TYPE LIKE ?
             OR TICKET_TYPE LIKE ?
             OR TITLE_FULL LIKE ?
             OR TITLE_FULL LIKE ?
           )'
    );
    $upd->execute([
        $from,
        $toExcl,
        '%оазис%',
        '%oasis%',
        '%оазис%',
        '%oasis%',
    ]);

    $ensureIdx = static function (PDO $pdo, string $name, string $ddl): void {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
        );
        $st->execute(['base_fullstats', $name]);
        if ($st->fetchColumn()) {
            return;
        }
        $pdo->exec('ALTER TABLE base_fullstats ADD ' . $ddl);
    };
    $ensureIdx($pdo, 'idx_id_type', 'KEY idx_id_type (ID_TYPE)');
    $ensureIdx($pdo, 'idx_is_limited', 'KEY idx_is_limited (IS_LIMITED)');
    $ensureIdx($pdo, 'idx_is_payer', 'KEY idx_is_payer (IS_PAYER)');
    $ensureIdx($pdo, 'idx_oasis_ticket', 'KEY idx_oasis_ticket (IS_OASIS, TICKET_TYPE(64))');
};
