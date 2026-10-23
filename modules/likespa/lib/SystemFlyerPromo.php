<?php
declare(strict_types=1);

/**
 * Flyer + promoter terminal discounts in cfg/modules/likespa.ini [likespa]:
 * flyer_enabled, flyer_discount, promo_position_ids (3,5), promo_discounts / discount_types (typeId:pct,…).
 * Legacy base_system encodings are decoded only by the copy migration.
 */
final class SystemFlyerPromo
{
    /** @var array{
     *   flyer_enabled:int,
     *   flyer_discount:int,
     *   promo_position_ids:list<int>,
     *   promo_discounts:array<int,int|string>,
     *   discount_types:array<int,int|string>
     * }|null */
    private static $loadCache = null;

    /**
     * @return array{
     *   flyer_enabled:int,
     *   flyer_discount:int,
     *   promo_position_ids:list<int>,
     *   promo_discounts:array<int,int|string>,
     *   discount_types:array<int,int|string>
     * }
     */
    public static function load(): array
    {
        if (self::$loadCache !== null) {
            return self::$loadCache;
        }
        self::$loadCache = [
            'flyer_enabled' => (int)Config::get('likespa', 'flyer_enabled', 0) > 0 ? 1 : 0,
            'flyer_discount' => max(0, min(100, (int)Config::get('likespa', 'flyer_discount', 0))),
            'promo_position_ids' => self::parseIdList((string)Config::get('likespa', 'promo_position_ids', '')),
            'promo_discounts' => self::parsePctMap((string)Config::get('likespa', 'promo_discounts', '')),
            'discount_types' => self::parsePctMap((string)Config::get('likespa', 'discount_types', '')),
        ];
        return self::$loadCache;
    }

    public static function resetLoadCache(): void
    {
        self::$loadCache = null;
        if (class_exists(TermScansRepository::class, false)) {
            TermScansRepository::resetPromoConfigCache();
        }
    }

    /**
     * @param array<string,mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        $flyerEnabled = !empty($post['flyer_enabled']) ? 1 : 0;
        $flyerDiscount = max(0, min(100, (int)($post['flyer_discount'] ?? 0)));

        $positionIds = [];
        $rawPositions = $post['promo_position_ids'] ?? [];
        if (is_array($rawPositions)) {
            foreach ($rawPositions as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $positionIds[] = $id;
                }
            }
        }
        Config::set('likespa', 'flyer_enabled', (string)$flyerEnabled);
        Config::set('likespa', 'flyer_discount', (string)$flyerDiscount);
        Config::set('likespa', 'promo_position_ids', self::formatIdList($positionIds));

        // Only rewrite the type grids when submitted (avoid wipe if procedures offline).
        if (isset($post['promo_discounts']) && is_array($post['promo_discounts'])) {
            Config::set('likespa', 'promo_discounts', self::formatPctMap(self::parseTypePctGrid($post['promo_discounts'])));
        }
        if (isset($post['discount_types']) && is_array($post['discount_types'])) {
            Config::set('likespa', 'discount_types', self::formatPctMap(self::parseTypePctGrid($post['discount_types'])));
        }
        self::resetLoadCache();
    }

    /** @return list<int> "3,5" → [3,5] */
    public static function parseIdList(string $raw): array
    {
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = (int)trim($part);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $out = array_keys($ids);
        sort($out);
        return $out;
    }

    /** @param list<int> $ids */
    public static function formatIdList(array $ids): string
    {
        return implode(',', self::parseIdList(implode(',', array_map('strval', $ids))));
    }

    /** @return array<int,int|string> "3:10,5:" → [3=>10, 5=>''] (empty = off) */
    public static function parsePctMap(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || strpos($part, ':') === false) {
                continue;
            }
            [$typeId, $pct] = array_map('trim', explode(':', $part, 2));
            $typeId = (int)$typeId;
            if ($typeId < 1) {
                continue;
            }
            $out[$typeId] = ($pct === '' || (int)$pct === 0) ? '' : max(0, min(100, (int)$pct));
        }
        ksort($out);
        return $out;
    }

    /** @param array<int,int|string> $map */
    public static function formatPctMap(array $map): string
    {
        $parts = [];
        ksort($map);
        foreach ($map as $typeId => $pct) {
            $typeId = (int)$typeId;
            if ($typeId < 1) {
                continue;
            }
            $pctStr = trim((string)$pct);
            $parts[] = $typeId . ':' . (($pctStr === '' || (int)$pctStr === 0) ? '' : (string)max(0, min(100, (int)$pctStr)));
        }
        return implode(',', $parts);
    }

    /**
     * @param array<int|string,mixed> $raw
     * @return array<int,string>
     */
    private static function parseTypePctGrid(array $raw): array
    {
        $discounts = [];
        foreach ($raw as $typeId => $pct) {
            $typeId = (int)$typeId;
            if ($typeId < 1) {
                continue;
            }
            $pctStr = trim((string)$pct);
            if ($pctStr === '') {
                $discounts[$typeId] = '';
                continue;
            }
            $pctInt = max(0, min(100, (int)$pctStr));
            $discounts[$typeId] = $pctInt > 0 ? (string)$pctInt : '';
        }
        return $discounts;
    }

    /**
     * Legacy base_system.PROMO_POSITION_ID ("#3#,#5#" or "3") — copy migration only.
     *
     * @return list<int>
     */
    public static function decodePositionIds(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0') {
            return [];
        }
        if (preg_match_all('/#(\d+)#/', $raw, $m)) {
            $ids = array_map('intval', $m[1]);
            $ids = array_values(array_unique(array_filter($ids)));
            sort($ids);
            return $ids;
        }
        // Legacy single numeric id.
        if (ctype_digit($raw)) {
            $id = (int)$raw;
            return $id > 0 ? [$id] : [];
        }
        return [];
    }

    /**
     * Legacy base_system base64(serialize) discount map — copy migration only.
     *
     * @return array<int,int|string> typeId => percent (0/empty = off)
     */
    public static function decodeDiscounts(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = @unserialize(base64_decode($raw, true) ?: '', ['allowed_classes' => false]);
        if (!is_array($decoded)) {
            $decoded = @unserialize(base64_decode($raw) ?: '', ['allowed_classes' => false]);
        }
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $typeId => $pct) {
            $typeId = (int)$typeId;
            if ($typeId < 1) {
                continue;
            }
            $pctStr = trim((string)$pct);
            if ($pctStr === '' || (int)$pctStr === 0) {
                $out[$typeId] = '';
                continue;
            }
            $out[$typeId] = max(0, min(100, (int)$pctStr));
        }
        return $out;
    }

    /** @return array<int,string> id => name */
    public static function statusOptions(): array
    {
        if (!Database::tableExists('base_admin_statuses')) {
            return [];
        }
        $out = [];
        $sql = 'SELECT ID, NAME FROM base_admin_statuses WHERE ENABLED > 0 ORDER BY NAME ASC';
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['ID']] = (string)$r['NAME'];
        }
        return $out;
    }

    /** @return array<int,string> id => label */
    public static function procedureTypeOptions(): array
    {
        if (!class_exists('ProcedureTypesRepository')) {
            return [];
        }
        return ProcedureTypesRepository::names();
    }
}
