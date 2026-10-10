<?php
declare(strict_types=1);

/**
 * Legacy IS_STATUS_CARD / is_status_card meant “hide on public client site”.
 * Rename to hide_on_site for clarity.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_products')) {
        return;
    }
    if (Database::columnExists('base_products', 'hide_on_site')) {
        return;
    }
    if (Database::columnExists('base_products', 'is_status_card')) {
        $pdo->exec(
            'ALTER TABLE `base_products`
             CHANGE `is_status_card` `hide_on_site` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0'
        );
        return;
    }
    $pdo->exec(
        'ALTER TABLE `base_products`
         ADD COLUMN `hide_on_site` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
         AFTER `stock_max`'
    );
};
