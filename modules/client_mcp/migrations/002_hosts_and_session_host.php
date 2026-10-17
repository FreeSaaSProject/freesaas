<?php
declare(strict_types=1);

/**
 * Per-storefront-host MCP settings + bind sessions to the host that issued the token.
 * SITE_HOST_ID 0 = single-site install without base_site_hosts rows.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_client_mcp_hosts (
            SITE_HOST_ID INT UNSIGNED NOT NULL,
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            SERVER_NAME VARCHAR(80) NOT NULL DEFAULT '',
            DESCRIPTION VARCHAR(400) NOT NULL DEFAULT '',
            CONSENT_URL VARCHAR(500) NOT NULL DEFAULT '',
            DISABLED_TOOLS TEXT NOT NULL,
            DT_UPDATE INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (SITE_HOST_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $col = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_client_mcp_sessions' AND COLUMN_NAME = 'SITE_HOST_ID'"
    );
    if ($col !== false && (int)$col->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE base_client_mcp_sessions
             ADD COLUMN SITE_HOST_ID INT UNSIGNED NOT NULL DEFAULT 0 AFTER TOKEN_HASH,
             ADD KEY idx_host_create (SITE_HOST_ID, DT_CREATE)'
        );
    }
};
