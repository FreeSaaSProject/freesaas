<?php
declare(strict_types=1);

/**
 * Oper-parity terminal price: max(client/flyer %, promo %) with DISCOUNTS_TYPES cap on promo.
 * (likespa.hm terminalApplyBestDiscount)
 */
final class TerminalDiscount
{
    /**
     * @param array<int,int|string> $typeLimits DISCOUNTS_TYPES map typeId => max %
     * @return array{price:float,pct:int}
     */
    public static function bestPrice(
        float $listPrice,
        float $clientOrFlyerPct,
        int $typeId,
        bool $promoActive,
        array $typeLimits = []
    ): array {
        $bestPct = max(0, min(100, (int)$clientOrFlyerPct));
        if ($promoActive && class_exists(TermScansRepository::class, false)) {
            $promoPct = TermScansRepository::discountForType($typeId);
            if (array_key_exists($typeId, $typeLimits)) {
                $lim = max(0, min(100, (int)$typeLimits[$typeId]));
                if ($promoPct > $lim) {
                    $promoPct = $lim;
                }
            }
            if ($promoPct > $bestPct) {
                $bestPct = $promoPct;
            }
        }
        if ($listPrice <= 0 || $bestPct <= 0) {
            return ['price' => round(max(0.0, $listPrice), 2), 'pct' => $bestPct];
        }
        $paid = round($listPrice * (100 - $bestPct) / 100, 2);
        return ['price' => $paid, 'pct' => $bestPct];
    }

    /**
     * Cap flyer % by the tightest DISCOUNTS_TYPES limit among given type ids (oper category preload).
     *
     * @param list<int> $typeIds
     * @param array<int,int|string> $typeLimits
     */
    public static function capFlyerByTypes(float $flyerPct, array $typeIds, array $typeLimits): float
    {
        $flyerPct = max(0.0, min(100.0, $flyerPct));
        if ($flyerPct <= 0 || $typeLimits === [] || $typeIds === []) {
            return $flyerPct;
        }
        $setLimit = 100;
        $found = false;
        foreach ($typeIds as $typeId) {
            $typeId = (int)$typeId;
            if ($typeId < 1 || !array_key_exists($typeId, $typeLimits)) {
                continue;
            }
            $found = true;
            $lim = max(0, min(100, (int)$typeLimits[$typeId]));
            if ($lim < $setLimit) {
                $setLimit = $lim;
            }
        }
        if (!$found) {
            return $flyerPct;
        }
        return min($flyerPct, (float)$setLimit);
    }
}
