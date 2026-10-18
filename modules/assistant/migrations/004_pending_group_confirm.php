<?php
declare(strict_types=1);

/**
 * Group assistant: manager-confirm pending (capability mode + group chat binding).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_assistant_pending')) {
        return;
    }
    $cols = [
        'CONFIRM_MODE' => "VARCHAR(16) NOT NULL DEFAULT 'self'",
        'REQUIRED_PERMISSION' => "VARCHAR(128) NOT NULL DEFAULT ''",
        'GROUP_CHAT_ID' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'GROUP_PLATFORM' => "VARCHAR(16) NOT NULL DEFAULT ''",
        'CONFIRMED_BY' => 'INT UNSIGNED NOT NULL DEFAULT 0',
    ];
    foreach ($cols as $col => $ddl) {
        if (Database::columnExists('base_assistant_pending', $col)) {
            continue;
        }
        $pdo->exec('ALTER TABLE base_assistant_pending ADD COLUMN `' . $col . '` ' . $ddl);
    }
    // Helpful lookup for «@бот, подтверждаю» in a group.
    $idx = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'base_assistant_pending'
           AND INDEX_NAME = 'idx_assistant_pending_group'"
    );
    $n = $idx ? (int)$idx->fetchColumn() : 0;
    if ($n === 0) {
        $pdo->exec(
            'ALTER TABLE base_assistant_pending
             ADD KEY idx_assistant_pending_group (GROUP_PLATFORM, GROUP_CHAT_ID, STATUS, EXPIRES_AT)'
        );
    }
};
