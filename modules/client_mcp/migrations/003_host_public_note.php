<?php
declare(strict_types=1);

/**
 * Per-host visible storefront note pointing people/agents to the MCP endpoint.
 */
return static function (PDO $pdo): void {
    $cols = [
        'SHOW_NOTE' => "ADD COLUMN SHOW_NOTE TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 AFTER CONSENT_URL",
        'NOTE_TEXT' => "ADD COLUMN NOTE_TEXT VARCHAR(400) NOT NULL DEFAULT '' AFTER SHOW_NOTE",
    ];
    foreach ($cols as $name => $ddl) {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_client_mcp_hosts' AND COLUMN_NAME = ?"
        );
        $st->execute([$name]);
        if ((int)$st->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE base_client_mcp_hosts ' . $ddl);
        }
    }
};
