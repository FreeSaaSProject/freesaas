<?php
declare(strict_types=1);

/**
 * Bind CMS pages and redirects to a storefront host.
 * Existing rows → default host (safer than shared 0 for multi-domain cutover).
 */
return static function (PDO $pdo): void {
    $defaultId = 0;
    if (Database::tableExists('base_site_hosts')) {
        $st = $pdo->query(
            'SELECT id FROM base_site_hosts WHERE is_default = 1 AND enabled = 1 ORDER BY id ASC LIMIT 1'
        );
        $defaultId = (int)($st ? $st->fetchColumn() : 0);
        if ($defaultId < 1) {
            $st = $pdo->query('SELECT id FROM base_site_hosts ORDER BY id ASC LIMIT 1');
            $defaultId = (int)($st ? $st->fetchColumn() : 0);
        }
    }

    if (Database::tableExists('base_page_categories')
        && !Database::columnExists('base_page_categories', 'site_host_id')
    ) {
        $pdo->exec(
            'ALTER TABLE `base_page_categories`
             ADD COLUMN `site_host_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `id`,
             ADD KEY `idx_site_host` (`site_host_id`)'
        );
        if ($defaultId > 0) {
            $pdo->exec(
                'UPDATE `base_page_categories` SET `site_host_id` = ' . (int)$defaultId
                . ' WHERE `site_host_id` = 0'
            );
        }
    }

    if (Database::tableExists('base_redirects')
        && !Database::columnExists('base_redirects', 'site_host_id')
    ) {
        $pdo->exec(
            'ALTER TABLE `base_redirects`
             ADD COLUMN `site_host_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `ID`,
             ADD KEY `idx_site_host` (`site_host_id`)'
        );
        if ($defaultId > 0) {
            $pdo->exec(
                'UPDATE `base_redirects` SET `site_host_id` = ' . (int)$defaultId
                . ' WHERE `site_host_id` = 0'
            );
        }
    }
};
