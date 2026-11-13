<?php
declare(strict_types=1);

/**
 * Per-host storefront locale (ru, en, …). Empty = app/session locale as before.
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_site_hosts')) {
        return;
    }
    if (!Database::columnExists('base_site_hosts', 'locale')) {
        $pdo->exec(
            "ALTER TABLE base_site_hosts
             ADD COLUMN locale VARCHAR(8) NOT NULL DEFAULT '' AFTER theme"
        );
    }
};
