<?php
declare(strict_types=1);

/**
 * Indexes for top-bar notice COUNTs on base_poll_invites (~90k rows).
 * Also drop duplicate legacy MyISAM keys (ID_USER_2/3, DT_CREATE_2/3).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_poll_invites')) {
        return;
    }

    $indexExists = static function (string $name) use ($pdo): bool {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'base_poll_invites\'
               AND INDEX_NAME = ? LIMIT 1'
        );
        $st->execute([$name]);
        return (bool)$st->fetchColumn();
    };

    foreach (['ID_USER_2', 'ID_USER_3', 'DT_CREATE_2', 'DT_CREATE_3'] as $dup) {
        if (!$indexExists($dup)) {
            continue;
        }
        try {
            $pdo->exec('ALTER TABLE base_poll_invites DROP INDEX `' . str_replace('`', '``', $dup) . '`');
        } catch (Throwable $e) {
            // ignore
        }
    }

    $add = static function (string $name, string $cols) use ($pdo, $indexExists): void {
        if ($indexExists($name)) {
            return;
        }
        try {
            $pdo->exec('ALTER TABLE base_poll_invites ADD INDEX `' . $name . '` (' . $cols . ')');
        } catch (Throwable $e) {
            // ignore conflict
        }
    };

    // Open tray: DT_COMPLETED=0 AND STATUS!=2 AND linked filter
    $add('idx_poll_invites_open', 'DT_COMPLETED, STATUS, ID_OPERATOR_LINKED');
    // Next-call due today
    $add('idx_poll_invites_nextcall', 'STATUS, DT_NEXTCALL, ID_OPERATOR_LINKED');
    // Supervisor sign queue
    $add('idx_poll_invites_sign', 'SIGN_REQ, ID_SUPERVISOR_SIGNED, DT_COMPLETED');
    // Per-poll open tray chips
    $add('idx_poll_invites_poll_open', 'ID_POLL, DT_COMPLETED, STATUS, ID_OPERATOR_LINKED');
};
