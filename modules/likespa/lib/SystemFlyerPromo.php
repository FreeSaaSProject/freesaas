<?php
declare(strict_types=1);

/**
 * Flyer + promoter terminal discounts stored in base_system
 * (oper show_site_sets: flyerEnabled, flyerDiscount, PROMO_POSITION_ID, PROMO_DISCOUNTS).
 */
final class SystemFlyerPromo
{
    /**
     * @return array{
     *   flyer_enabled:int,
     *   flyer_discount:int,
     *   promo_position_ids:list<int>,
     *   promo_discounts:array<int,int>
     * }
     */
    public static function load(): array
    {
        $row = SystemRepository::get() ?: [];
        return [
            'flyer_enabled' => (int)($row['flyerEnabled'] ?? 0),
            'flyer_discount' => (int)($row['flyerDiscount'] ?? 0),
            'promo_position_ids' => self::decodePositionIds((string)($row['PROMO_POSITION_ID'] ?? '')),
            'promo_discounts' => self::decodeDiscounts((string)($row['PROMO_DISCOUNTS'] ?? '')),
        ];
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
        $positionIds = array_values(array_unique($positionIds));
        sort($positionIds);

        $payload = [
            'flyerEnabled' => $flyerEnabled,
            'flyerDiscount' => $flyerDiscount,
            'PROMO_POSITION_ID' => self::encodePositionIds($positionIds),
        ];

        // Only rewrite PROMO_DISCOUNTS when the type grid was submitted (avoid wipe if procedures offline).
        if (isset($post['promo_discounts']) && is_array($post['promo_discounts'])) {
            $discounts = [];
            foreach ($post['promo_discounts'] as $typeId => $pct) {
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
            $payload['PROMO_DISCOUNTS'] = self::encodeDiscounts($discounts);
        }

        self::persist($payload);
    }

    /**
     * @param array{
     *   flyerEnabled?:int,
     *   flyerDiscount?:int,
     *   PROMO_POSITION_ID?:string,
     *   PROMO_DISCOUNTS?:string
     * } $data
     */
    private static function persist(array $data): void
    {
        $row = SystemRepository::get();
        if (!$row) {
            throw new RuntimeException('base_system is empty — run data migrate');
        }
        $cols = [];
        $params = [];
        $map = [
            'flyerEnabled' => 'flyerEnabled',
            'flyerDiscount' => 'flyerDiscount',
            'PROMO_POSITION_ID' => 'PROMO_POSITION_ID',
            'PROMO_DISCOUNTS' => 'PROMO_DISCOUNTS',
        ];
        foreach ($map as $key => $col) {
            if (!array_key_exists($key, $data) || !Database::columnExists('base_system', $col)) {
                continue;
            }
            $cols[] = '`' . $col . '` = ?';
            if ($col === 'flyerEnabled' || $col === 'flyerDiscount') {
                $params[] = (int)$data[$key];
            } else {
                $params[] = (string)$data[$key];
            }
        }
        if ($cols === []) {
            return;
        }
        $params[] = (int)$row['id'];
        Database::pdo()->prepare(
            'UPDATE base_system SET ' . implode(', ', $cols) . ' WHERE id = ?'
        )->execute($params);
    }

    /** @return list<int> */
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

    /** @param list<int> $ids */
    public static function encodePositionIds(array $ids): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids);
        if ($ids === []) {
            return '';
        }
        $parts = [];
        foreach ($ids as $id) {
            $parts[] = '#' . $id . '#';
        }
        return implode(',', $parts);
    }

    /** @return array<int,int|string> typeId => percent (0/empty = off) */
    public static function decodeDiscounts(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = @unserialize(base64_decode($raw, true) ?: '');
        if (!is_array($decoded)) {
            $decoded = @unserialize(base64_decode($raw) ?: '');
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

    /**
     * Keep oper-compatible base64(serialize) payload (string percents / empty).
     *
     * @param array<int,string|int> $map
     */
    public static function encodeDiscounts(array $map): string
    {
        $stored = [];
        foreach ($map as $typeId => $pct) {
            $typeId = (int)$typeId;
            if ($typeId < 1) {
                continue;
            }
            $pctStr = trim((string)$pct);
            if ($pctStr === '' || (int)$pctStr === 0) {
                $stored[$typeId] = '';
            } else {
                $stored[$typeId] = (string)max(0, min(100, (int)$pctStr));
            }
        }
        return base64_encode(serialize($stored));
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
