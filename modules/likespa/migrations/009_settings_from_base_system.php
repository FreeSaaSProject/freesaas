<?php
declare(strict_types=1);

/**
 * Copy legacy base_system flyer / promo / discount caps / FREE_TICKET → cfg/modules/likespa.ini [likespa].
 * Legacy encodings (#id#, base64(serialize)) become readable lists: 3,5 and 3:10,5:.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_system')) {
        return;
    }
    $row = $pdo->query('SELECT * FROM base_system ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return;
    }
    require_once base_path('modules/likespa/lib/SystemFlyerPromo.php');

    if (array_key_exists('flyerEnabled', $row)) {
        Config::set('likespa', 'flyer_enabled', (int)$row['flyerEnabled'] > 0 ? '1' : '0');
    }
    if (array_key_exists('flyerDiscount', $row)) {
        Config::set('likespa', 'flyer_discount', (string)max(0, min(100, (int)$row['flyerDiscount'])));
    }
    if (array_key_exists('PROMO_POSITION_ID', $row)) {
        Config::set('likespa', 'promo_position_ids', SystemFlyerPromo::formatIdList(
            SystemFlyerPromo::decodePositionIds((string)$row['PROMO_POSITION_ID'])
        ));
    }
    if (array_key_exists('PROMO_DISCOUNTS', $row)) {
        Config::set('likespa', 'promo_discounts', SystemFlyerPromo::formatPctMap(
            SystemFlyerPromo::decodeDiscounts((string)$row['PROMO_DISCOUNTS'])
        ));
    }
    if (array_key_exists('DISCOUNTS_TYPES', $row)) {
        Config::set('likespa', 'discount_types', SystemFlyerPromo::formatPctMap(
            SystemFlyerPromo::decodeDiscounts((string)$row['DISCOUNTS_TYPES'])
        ));
    }
    if (array_key_exists('FREE_TICKET', $row)) {
        Config::set('likespa', 'free_ticket', (string)max(0, (int)$row['FREE_TICKET']));
    }
};
