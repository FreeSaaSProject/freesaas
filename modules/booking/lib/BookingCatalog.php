<?php
declare(strict_types=1);

/**
 * Bookable services for staff / slot UI (not materials, not hidden categories).
 */
final class BookingCatalog
{
    /**
     * @return list<array<string,mixed>>
     */
    public static function listServices(int $limit = 500): array
    {
        if (!class_exists(ProductsRepository::class, false)) {
            return [];
        }
        $limit = max(1, min(2000, $limit));
        $raw = ProductsRepository::list([
            'enabled' => '1',
            'is_material' => '0',
            'order' => 'p.category ASC, p.num ASC, p.name ASC',
            'limit' => $limit,
            'offset' => 0,
        ]);
        $items = $raw['items'] ?? [];
        if ($items === []) {
            return [];
        }

        $hiddenCats = self::hiddenCategoryIds();
        $out = [];
        foreach ($items as $p) {
            $cat = (int)($p['category'] ?? 0);
            if ($cat > 0 && isset($hiddenCats[$cat])) {
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }

    /**
     * @return array<int,true>
     */
    public static function hiddenCategoryIds(): array
    {
        if (!class_exists(CategoriesRepository::class, false) || !Database::tableExists('base_categories')) {
            return [];
        }
        $hidden = [];
        $res = CategoriesRepository::list([
            'hidden' => '1',
            'limit' => 2000,
            'offset' => 0,
        ]);
        foreach ($res['items'] as $c) {
            $id = (int)($c['id'] ?? 0);
            if ($id > 0) {
                $hidden[$id] = true;
            }
        }
        return $hidden;
    }

    /**
     * Bookable services this operator can perform on $dayStart (rates / restrict).
     * Always includes $keepProductIds even if currently ineligible (so edit UI can show them).
     *
     * @param list<int> $keepProductIds
     * @return list<array<string,mixed>>
     */
    public static function listServicesForOperator(int $operatorId, int $dayStart, array $keepProductIds = [], int $limit = 500): array
    {
        $all = self::listServices($limit);
        $keep = [];
        foreach ($keepProductIds as $kid) {
            $kid = (int)$kid;
            if ($kid > 0) {
                $keep[$kid] = true;
            }
        }
        if ($operatorId < 1 || !class_exists(BookingOperatorGate::class, false)) {
            return $all;
        }
        $out = [];
        $seen = [];
        foreach ($all as $p) {
            $pid = (int)($p['id'] ?? 0);
            if ($pid < 1) {
                continue;
            }
            $typeId = max(0, (int)($p['type_id'] ?? 0));
            if ($typeId < 1 && class_exists(ProductsRepository::class, false)) {
                $full = ProductsRepository::find($pid);
                $typeId = $full ? max(0, (int)($full['type_id'] ?? 0)) : 0;
                if ($full && empty($p['name'])) {
                    $p['name'] = (string)($full['name'] ?? '');
                }
            }
            $probe = [['id' => $pid, 'type_id' => $typeId]];
            $ok = BookingOperatorGate::canPerform($operatorId, $probe, $dayStart, false);
            if (!$ok && !isset($keep[$pid])) {
                continue;
            }
            $p['type_id'] = $typeId;
            $out[] = $p;
            $seen[$pid] = true;
        }
        foreach ($keep as $pid => $_) {
            if (isset($seen[$pid])) {
                continue;
            }
            if (!class_exists(ProductsRepository::class, false)) {
                break;
            }
            $full = ProductsRepository::find($pid);
            if ($full === null) {
                continue;
            }
            $price = (float)($full['price3'] ?? 0);
            if ($price <= 0) {
                $price = (float)($full['price'] ?? 0);
            }
            $out[] = [
                'id' => $pid,
                'name' => (string)($full['name'] ?? ''),
                'price' => $price,
                'price3' => $price,
                'type_id' => max(0, (int)($full['type_id'] ?? 0)),
                'time_sec' => (int)($full['time_sec'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Group products by category name for the picker UI.
     *
     * @param list<array<string,mixed>> $products
     * @return list<array{id:int,name:string,products:list<array<string,mixed>>}>
     */
    public static function groupByCategory(array $products): array
    {
        $names = class_exists(CategoriesRepository::class, false)
            ? CategoriesRepository::names()
            : [];
        $groups = [];
        foreach ($products as $p) {
            $cid = (int)($p['category'] ?? 0);
            if (!isset($groups[$cid])) {
                $groups[$cid] = [
                    'id' => $cid,
                    'name' => $cid > 0
                        ? (string)($names[$cid] ?? ($p['category_name'] ?? ('#' . $cid)))
                        : t('booking.new.cat_root'),
                    'products' => [],
                ];
            }
            $groups[$cid]['products'][] = $p;
        }
        return array_values($groups);
    }
}
