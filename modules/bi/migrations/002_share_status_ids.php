<?php
declare(strict_types=1);

/**
 * Staff share allowlist by admin status (role / должность).
 */
return static function (PDO $pdo): void {
    $st = $pdo->query("SHOW COLUMNS FROM base_bi_shares LIKE 'STAFF_STATUS_IDS'");
    $exists = $st && $st->fetch(PDO::FETCH_ASSOC);
    if ($exists) {
        return;
    }
    $pdo->exec(
        "ALTER TABLE base_bi_shares
         ADD COLUMN STAFF_STATUS_IDS TEXT NOT NULL AFTER STAFF_USER_IDS"
    );
};
