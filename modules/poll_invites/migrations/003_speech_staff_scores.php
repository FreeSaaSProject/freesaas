<?php
declare(strict_types=1);

/**
 * Denormalized procedure staff + numeric speech scores for master rating reports.
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    $add = static function (string $col, string $ddl) use ($pdo): void {
        if (Database::columnExists('base_poll_invites', $col)) {
            return;
        }
        $pdo->exec('ALTER TABLE base_poll_invites ADD COLUMN ' . $ddl);
    };
    $add('ID_PROC_OPERATOR', 'ID_PROC_OPERATOR INT UNSIGNED NOT NULL DEFAULT 0');
    $add('ID_PROC_ADMIN', 'ID_PROC_ADMIN INT UNSIGNED NOT NULL DEFAULT 0');
    $add('SCORE_STAFF', 'SCORE_STAFF TINYINT UNSIGNED NULL DEFAULT NULL');
    $add('SCORE_SERVICE', 'SCORE_SERVICE TINYINT UNSIGNED NULL DEFAULT NULL');

    // Indexes for master rating queries.
    $idx = static function (string $name, string $cols) use ($pdo): void {
        $st = $pdo->query("SHOW INDEX FROM base_poll_invites WHERE Key_name = " . $pdo->quote($name));
        if ($st && $st->fetch()) {
            return;
        }
        $pdo->exec("CREATE INDEX {$name} ON base_poll_invites ({$cols})");
    };
    $idx('idx_poll_invites_proc_op', 'ID_PROC_OPERATOR, DT_COMPLETED');
    $idx('idx_poll_invites_score_staff', 'SCORE_STAFF, ID_PROC_OPERATOR');
};
