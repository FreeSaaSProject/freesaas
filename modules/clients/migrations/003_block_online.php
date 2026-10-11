<?php
declare(strict_types=1);

/**
 * Flag: client status blocks storefront online booking/checkout.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_client_statuses')) {
        return;
    }
    if (!Database::columnExists('base_client_statuses', 'block_online')) {
        $pdo->exec(
            'ALTER TABLE `base_client_statuses`
             ADD COLUMN `block_online` TINYINT(1) NOT NULL DEFAULT 0 AFTER `enabled`'
        );
    }
    // Seed known harassment / ban status (legacy id 7 on LikeSpa).
    $pdo->exec(
        "UPDATE base_client_statuses
         SET block_online = 1
         WHERE block_online = 0
           AND (id = 7 OR name LIKE '%ХАРРАСМЕНТ%' OR name LIKE '%HARASS%')"
    );
};
