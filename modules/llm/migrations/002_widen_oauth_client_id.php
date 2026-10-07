<?php
declare(strict_types=1);

/**
 * CIMD client_id values are HTTPS URLs (can exceed 64 chars).
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    foreach (['base_oauth_clients', 'base_oauth_codes', 'base_oauth_tokens'] as $table) {
        $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'CLIENT_ID'");
        $col = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if (!is_array($col)) {
            continue;
        }
        $type = strtolower((string)($col['Type'] ?? ''));
        if (strpos($type, 'varchar(255)') !== false || strpos($type, 'varchar(512)') !== false) {
            continue;
        }
        $pdo->exec("ALTER TABLE `{$table}` MODIFY CLIENT_ID VARCHAR(255) NOT NULL");
    }
};
