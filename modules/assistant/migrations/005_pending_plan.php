<?php
declare(strict_types=1);

/**
 * Multi-step plans: writes queued in one assistant turn share PLAN_ID and confirm together.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_assistant_pending')) {
        return;
    }
    $cols = [
        'PLAN_ID' => "VARCHAR(32) NOT NULL DEFAULT ''",
        'PLAN_STEP' => 'INT UNSIGNED NOT NULL DEFAULT 0',
    ];
    foreach ($cols as $col => $ddl) {
        if (Database::columnExists('base_assistant_pending', $col)) {
            continue;
        }
        $pdo->exec('ALTER TABLE base_assistant_pending ADD COLUMN `' . $col . '` ' . $ddl);
    }
    $idx = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'base_assistant_pending'
           AND INDEX_NAME = 'idx_assistant_pending_plan'"
    );
    $n = $idx ? (int)$idx->fetchColumn() : 0;
    if ($n === 0) {
        $pdo->exec('ALTER TABLE base_assistant_pending ADD KEY idx_assistant_pending_plan (PLAN_ID)');
    }
};
