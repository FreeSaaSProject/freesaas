<?php
declare(strict_types=1);

/**
 * Per-host FREE_TICKET storefront option (LikeSpa on, Balance off).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_site_hosts')) {
        return;
    }
    if (!Database::columnExists('base_site_hosts', 'free_ticket')) {
        $pdo->exec(
            'ALTER TABLE base_site_hosts
             ADD COLUMN free_ticket TINYINT UNSIGNED NOT NULL DEFAULT 0
             AFTER catalog_root_id'
        );
    }
    // Seed LikeSpa public hosts; leave Balance / others at 0.
    $pdo->exec(
        "UPDATE base_site_hosts
         SET free_ticket = 1
         WHERE free_ticket = 0
           AND (
             host LIKE '%likespa%'
             OR theme = 'likespa'
           )"
    );
};
