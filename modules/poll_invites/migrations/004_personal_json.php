<?php
declare(strict_types=1);

/** Per-invite personalisation for public links: {"skip_qids":[..], "hints":{qid:text}, "vars":{..}}. */
return static function (PDO $pdo): void {
    $st = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_poll_invites' AND COLUMN_NAME = 'PERSONAL_JSON'"
    );
    if ((int)($st ? $st->fetchColumn() : 0) === 0) {
        $pdo->exec('ALTER TABLE base_poll_invites ADD COLUMN PERSONAL_JSON TEXT NULL');
    }
};
