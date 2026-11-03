<?php
declare(strict_types=1);

/**
 * Admin printable price list from base_categories / base_products (oper show_prices port).
 * Brand split = category roots + display title (soft sites host presets).
 */
final class PriceListPrinter
{
    public const PRICE_COLS = ['price', 'price2', 'price3', 'price4', 'price5'];

    /** Household services (oper typeOfService=2). */
    public const KIND_HOUSEHOLD = 2;

    /** Medical services (oper typeOfService=1). */
    public const KIND_MEDICAL = 1;

    /** No procedure-type filter. */
    public const KIND_ALL = 0;

    /**
     * @param array<string,mixed> $get
     * @return array{
     *   title:string,
     *   roots:list<int>,
     *   host_id:int,
     *   kind:int,
     *   main_col:string,
     *   strike_col:string,
     *   hide_descr:bool,
     *   show_discount:bool,
     *   cols:int,
     *   print_mode:bool,
     *   hidden:list<int>,
     *   num_left_max:int,
     *   num_right_min:int
     * }
     */
    public static function optionsFromRequest(array $get): array
    {
        $hostId = max(0, (int)($get['host'] ?? 0));
        $title = trim((string)($get['title'] ?? ''));
        $rootsRaw = $get['roots'] ?? '';
        if (is_array($rootsRaw)) {
            $roots = [];
            foreach ($rootsRaw as $rid) {
                $rid = (int)$rid;
                if ($rid > 0) {
                    $roots[] = $rid;
                }
            }
            $roots = array_values(array_unique($roots));
        } else {
            $roots = self::parseIdList((string)$rootsRaw);
        }

        if ($hostId > 0 && ($roots === [] || $title === '')) {
            foreach (self::hostPresets() as $preset) {
                if ((int)$preset['id'] === $hostId) {
                    if ($roots === []) {
                        $roots = $preset['roots'];
                    }
                    if ($title === '') {
                        $title = $preset['title'];
                    }
                    break;
                }
            }
        }

        $main = (string)($get['main'] ?? 'price');
        if ($main !== 'none' && !in_array($main, self::PRICE_COLS, true)) {
            $main = 'price';
        }
        $strike = (string)($get['strike'] ?? 'none');
        if ($strike !== 'none' && !in_array($strike, self::PRICE_COLS, true)) {
            $strike = 'none';
        }

        $kind = (int)($get['kind'] ?? self::KIND_HOUSEHOLD);
        if (!in_array($kind, [self::KIND_ALL, self::KIND_MEDICAL, self::KIND_HOUSEHOLD], true)) {
            $kind = self::KIND_HOUSEHOLD;
        }
        if (!self::proceduresAvailable() && $kind !== self::KIND_ALL) {
            $kind = self::KIND_ALL;
        }

        $cols = (int)($get['cols'] ?? 2);
        if ($cols !== 1) {
            $cols = 2;
        }

        return [
            'title' => $title !== '' ? mb_substr($title, 0, 200) : t('catalog.prices.default_title'),
            'roots' => $roots,
            'host_id' => $hostId,
            'kind' => $kind,
            'main_col' => $main,
            'strike_col' => $strike,
            'hide_descr' => !empty($get['hide_descr']),
            'show_discount' => !empty($get['show_discount']),
            'cols' => $cols,
            'print_mode' => !empty($get['print']),
            'hidden' => self::parseIdList((string)($get['hidden'] ?? '')),
            'num_left_max' => max(0, (int)($get['num_left_max'] ?? 50)),
            'num_right_min' => max(0, (int)($get['num_right_min'] ?? 51)),
        ];
    }

    /**
     * Soft presets from enabled site hosts with catalog_root_ids.
     *
     * @return list<array{id:int,label:string,title:string,roots:list<int>,href:string}>
     */
    public static function hostPresets(): array
    {
        if (!ModuleManager::isEnabled('sites') || !class_exists('SiteHostsRepository', false)) {
            $sitesLib = base_path('modules/sites/lib/SiteHostsRepository.php');
            if (ModuleManager::isEnabled('sites') && is_file($sitesLib)) {
                require_once $sitesLib;
            }
        }
        if (!ModuleManager::isEnabled('sites') || !class_exists('SiteHostsRepository', false)) {
            return [];
        }
        if (!method_exists('SiteHostsRepository', 'list')) {
            return [];
        }

        $out = [];
        $items = SiteHostsRepository::list([
            'enabled' => '1',
            'order' => 'is_default DESC, num ASC, host ASC',
            'limit' => 100,
        ])['items'] ?? [];

        foreach ($items as $row) {
            $roots = $row['catalog_root_ids'] ?? [];
            if (!is_array($roots)) {
                $roots = [];
            }
            $rootIds = [];
            foreach ($roots as $rid) {
                $rid = (int)$rid;
                if ($rid > 0) {
                    $rootIds[] = $rid;
                }
            }
            $rootIds = array_values(array_unique($rootIds));
            if ($rootIds === []) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            $name = trim((string)($row['name'] ?? ''));
            $host = trim((string)($row['host'] ?? ''));
            $title = $name !== '' ? $name : ($host !== '' ? $host : ('#' . $id));
            $label = $host !== '' && $name !== '' && $name !== $host
                ? $name . ' (' . $host . ')'
                : $title;
            $qs = http_build_query([
                'host' => $id,
                'title' => $title,
                'roots' => implode(',', $rootIds),
            ]);
            $out[] = [
                'id' => $id,
                'label' => $label,
                'title' => $title,
                'roots' => $rootIds,
                'href' => '/catalog/prices/print?' . $qs,
            ];
        }
        return $out;
    }

    public static function proceduresAvailable(): bool
    {
        return ModuleManager::isEnabled('procedures')
            && Database::tableExists('base_procedure_types');
    }

    /**
     * @param array<string,mixed> $opts from optionsFromRequest
     * @return array{
     *   options:array<string,mixed>,
     *   left:list<array<string,mixed>>,
     *   right:list<array<string,mixed>>,
     *   product_count:int,
     *   procedures:bool
     * }
     */
    public static function build(array $opts): array
    {
        $cats = self::loadCategories();
        $allowed = self::allowedCategoryIds($opts['roots'] ?? [], $cats);
        $typeFilter = self::typeIdFilter((int)($opts['kind'] ?? self::KIND_ALL));
        $hidden = [];
        foreach ($opts['hidden'] ?? [] as $hid) {
            $hidden[(int)$hid] = true;
        }
        $byCat = self::loadProductsByCategory($allowed, $typeFilter, $hidden);

        $numLeftMax = (int)($opts['num_left_max'] ?? 50);
        $numRightMin = (int)($opts['num_right_min'] ?? 51);
        $mainCol = (string)($opts['main_col'] ?? 'price');
        $strikeCol = (string)($opts['strike_col'] ?? 'none');
        $showDiscount = !empty($opts['show_discount']);

        $startParents = self::startParentIds($opts['roots'] ?? [], $cats, $allowed);

        $left = [];
        $right = [];
        foreach ($startParents as $parentId) {
            self::collectSections(
                $parentId,
                $cats,
                $allowed,
                $byCat,
                $mainCol,
                $strikeCol,
                $showDiscount,
                $numLeftMax,
                $numRightMin,
                $left,
                $right
            );
        }

        $productCount = 0;
        foreach ([$left, $right] as $side) {
            foreach ($side as $sec) {
                $productCount += count($sec['products'] ?? []);
            }
        }

        return [
            'options' => $opts,
            'left' => $left,
            'right' => $right,
            'product_count' => $productCount,
            'procedures' => self::proceduresAvailable(),
        ];
    }

    /**
     * Query string for current options (shareable / configurator apply).
     *
     * @param array<string,mixed> $opts
     */
    public static function queryString(array $opts, array $overrides = []): string
    {
        $merged = array_merge($opts, $overrides);
        $params = [
            'title' => (string)($merged['title'] ?? ''),
            'roots' => implode(',', array_map('intval', $merged['roots'] ?? [])),
            'host' => (int)($merged['host_id'] ?? 0),
            'kind' => (int)($merged['kind'] ?? self::KIND_HOUSEHOLD),
            'main' => (string)($merged['main_col'] ?? 'price'),
            'strike' => (string)($merged['strike_col'] ?? 'none'),
            'cols' => (int)($merged['cols'] ?? 2),
        ];
        if (!empty($merged['hide_descr'])) {
            $params['hide_descr'] = 1;
        }
        if (!empty($merged['show_discount'])) {
            $params['show_discount'] = 1;
        }
        if (!empty($merged['print_mode']) || !empty($merged['print'])) {
            $params['print'] = 1;
        }
        $hidden = $merged['hidden'] ?? [];
        if (is_array($hidden) && $hidden !== []) {
            $params['hidden'] = implode(',', array_map('intval', $hidden));
        }
        // Drop empty host/roots noise
        if ((int)$params['host'] < 1) {
            unset($params['host']);
        }
        if ($params['roots'] === '') {
            unset($params['roots']);
        }
        return http_build_query($params);
    }

    /** @return array<int,array<string,mixed>> */
    private static function loadCategories(): array
    {
        if (!Database::tableExists('base_categories')) {
            return [];
        }
        $out = [];
        foreach (
            Database::pdo()->query(
                'SELECT id, name, parent_id, num FROM base_categories ORDER BY num ASC, name ASC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r
        ) {
            $id = (int)$r['id'];
            $out[$id] = [
                'id' => $id,
                'name' => (string)$r['name'],
                'parent_id' => (int)$r['parent_id'],
                'num' => (int)$r['num'],
            ];
        }
        return $out;
    }

    /**
     * @param list<int> $roots
     * @param array<int,array<string,mixed>> $cats
     * @return array<int,true>|null null = all categories
     */
    private static function allowedCategoryIds(array $roots, array $cats): ?array
    {
        $rootIds = [];
        foreach ($roots as $rid) {
            $rid = (int)$rid;
            if ($rid > 0) {
                $rootIds[] = $rid;
            }
        }
        if ($rootIds === []) {
            return null;
        }
        $allow = [];
        foreach ($rootIds as $rid) {
            foreach (CategoriesRepository::descendantIds($rid) as $cid) {
                $allow[(int)$cid] = true;
            }
            // Also allow if root missing from descendant helper
            if (isset($cats[$rid])) {
                $allow[$rid] = true;
            }
        }
        return $allow;
    }

    /**
     * Top-level walk starts: roots themselves, or parent_id=0 when no roots.
     *
     * @param list<int> $roots
     * @param array<int,array<string,mixed>> $cats
     * @param array<int,true>|null $allowed
     * @return list<int> parent ids to pass to collectSections (children of these)
     */
    private static function startParentIds(array $roots, array $cats, ?array $allowed): array
    {
        $rootIds = [];
        foreach ($roots as $rid) {
            $rid = (int)$rid;
            if ($rid > 0 && isset($cats[$rid])) {
                $rootIds[] = $rid;
            }
        }
        if ($rootIds === []) {
            return [0];
        }
        // Walk each root as a virtual parent: collect from children of root AND products on root
        return $rootIds;
    }

    /**
     * @return list<int>|null null = no type filter; empty list = no matching types
     */
    private static function typeIdFilter(int $kind): ?array
    {
        if ($kind === self::KIND_ALL || !self::proceduresAvailable()) {
            return null;
        }
        $wantMedical = $kind === self::KIND_MEDICAL;
        $ids = [];
        foreach (
            Database::pdo()->query(
                'SELECT ID AS id, IS_MEDICAL AS is_medical FROM base_procedure_types'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r
        ) {
            $id = (int)$r['id'];
            if ($id === 11) {
                continue; // oper excluded TYPE 11
            }
            $isMed = !empty($r['is_medical']);
            if ($wantMedical ? $isMed : !$isMed) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * @param array<int,true>|null $allowed
     * @param list<int>|null $typeFilter
     * @param array<int,true> $hidden
     * @return array<int,list<array<string,mixed>>>
     */
    private static function loadProductsByCategory(?array $allowed, ?array $typeFilter, array $hidden): array
    {
        if (!Database::tableExists('base_products')) {
            return [];
        }
        $hideCol = Database::columnExists('base_products', 'hide_on_site') ? 'hide_on_site' : '0';
        $sql = "SELECT id, category, name, name_price, description,
                       price, price2, price3, price4, price5, type_id, num
                FROM base_products
                WHERE enabled = 1 AND is_material = 0 AND ($hideCol) = 0
                ORDER BY num ASC, name ASC";
        $rows = Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $typeAllow = null;
        if ($typeFilter !== null) {
            $typeAllow = [];
            foreach ($typeFilter as $tid) {
                $typeAllow[(int)$tid] = true;
            }
        }

        $byCat = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if (isset($hidden[$id])) {
                continue;
            }
            $catId = (int)$r['category'];
            if ($allowed !== null && !isset($allowed[$catId])) {
                continue;
            }
            $typeId = (int)($r['type_id'] ?? 0);
            if ($typeAllow !== null) {
                if ($typeId < 1 || !isset($typeAllow[$typeId])) {
                    continue;
                }
            }
            $namePrice = trim((string)($r['name_price'] ?? ''));
            $label = $namePrice !== '' ? $namePrice : (string)$r['name'];
            $byCat[$catId][] = [
                'id' => $id,
                'label' => $label,
                'description' => (string)($r['description'] ?? ''),
                'price' => (float)$r['price'],
                'price2' => (float)$r['price2'],
                'price3' => (float)$r['price3'],
                'price4' => (float)$r['price4'],
                'price5' => (float)$r['price5'],
            ];
        }
        return $byCat;
    }

    /**
     * @param array<int,array<string,mixed>> $cats
     * @param array<int,true>|null $allowed
     * @param array<int,list<array<string,mixed>>> $byCat
     * @param list<array<string,mixed>> $left
     * @param list<array<string,mixed>> $right
     */
    private static function collectSections(
        int $parentId,
        array $cats,
        ?array $allowed,
        array $byCat,
        string $mainCol,
        string $strikeCol,
        bool $showDiscount,
        int $numLeftMax,
        int $numRightMin,
        array &$left,
        array &$right
    ): void {
        // Products directly on a root folder
        if ($parentId > 0 && isset($byCat[$parentId]) && $byCat[$parentId] !== []) {
            $section = self::makeSection($parentId, $cats, $byCat[$parentId], $mainCol, $strikeCol, $showDiscount);
            if ($section !== null) {
                $num = (int)($cats[$parentId]['num'] ?? 0);
                if ($num <= $numLeftMax) {
                    $left[] = $section;
                } elseif ($num >= $numRightMin) {
                    $right[] = $section;
                } else {
                    $left[] = $section;
                }
            }
        }

        $children = [];
        foreach ($cats as $cat) {
            if ((int)$cat['parent_id'] !== $parentId) {
                continue;
            }
            $cid = (int)$cat['id'];
            if ($allowed !== null && !isset($allowed[$cid])) {
                continue;
            }
            $children[] = $cat;
        }
        usort($children, static function (array $a, array $b): int {
            return ((int)$a['num'] <=> (int)$b['num']) ?: strcmp((string)$a['name'], (string)$b['name']);
        });

        foreach ($children as $cat) {
            $cid = (int)$cat['id'];
            $num = (int)$cat['num'];
            $goods = $byCat[$cid] ?? [];
            if ($goods !== []) {
                $section = self::makeSection($cid, $cats, $goods, $mainCol, $strikeCol, $showDiscount);
                if ($section === null) {
                    continue;
                }
                // Column split uses this category's num (oper parity for top-level nums)
                $splitNum = $num;
                if ($parentId === 0 || self::isRootLevelParent($parentId, $cats, $allowed)) {
                    $splitNum = $num;
                } else {
                    // Nested: inherit top-most ancestor num within walk for column — use own num
                    $splitNum = $num;
                }
                if ($splitNum <= $numLeftMax) {
                    $left[] = $section;
                } elseif ($splitNum >= $numRightMin) {
                    $right[] = $section;
                } else {
                    $left[] = $section;
                }
            } else {
                self::collectSections(
                    $cid,
                    $cats,
                    $allowed,
                    $byCat,
                    $mainCol,
                    $strikeCol,
                    $showDiscount,
                    $numLeftMax,
                    $numRightMin,
                    $left,
                    $right
                );
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $cats
     * @param array<int,true>|null $allowed
     */
    private static function isRootLevelParent(int $parentId, array $cats, ?array $allowed): bool
    {
        if ($parentId < 1) {
            return true;
        }
        // Parent is a selected root (no further parent inside allowed set that is also a walk root)
        $p = $cats[$parentId]['parent_id'] ?? 0;
        if ($allowed === null) {
            return (int)$p === 0;
        }
        return !isset($allowed[(int)$p]) || (int)$p === 0;
    }

    /**
     * @param array<int,array<string,mixed>> $cats
     * @param list<array<string,mixed>> $goods
     * @return array<string,mixed>|null
     */
    private static function makeSection(
        int $catId,
        array $cats,
        array $goods,
        string $mainCol,
        string $strikeCol,
        bool $showDiscount
    ): ?array {
        $products = [];
        $hasStrike = false;
        foreach ($goods as $g) {
            $main = null;
            if ($mainCol !== 'none' && isset($g[$mainCol])) {
                $v = (float)$g[$mainCol];
                if ($v > 0) {
                    $main = $v;
                }
            }
            $strike = null;
            if ($strikeCol !== 'none' && isset($g[$strikeCol])) {
                $v = (float)$g[$strikeCol];
                if ($v > 0) {
                    $strike = $v;
                }
            }
            $useStrike = $strike !== null && $main !== null && $strike > $main;
            if ($useStrike) {
                $hasStrike = true;
            }
            $discountPct = 0;
            if ($useStrike && $showDiscount && $strike > 0) {
                $discountPct = (int)round((($strike - $main) / $strike) * 100);
            }
            $products[] = [
                'id' => (int)$g['id'],
                'label' => (string)$g['label'],
                'description' => (string)$g['description'],
                'main' => $main,
                'strike' => $useStrike ? $strike : null,
                'discount_pct' => $discountPct,
            ];
        }
        if ($products === []) {
            return null;
        }
        return [
            'category_id' => $catId,
            'category_name' => self::categoryPath($catId, $cats),
            'has_strike' => $hasStrike,
            'products' => $products,
        ];
    }

    /** @param array<int,array<string,mixed>> $cats */
    private static function categoryPath(int $catId, array $cats): string
    {
        $parts = [];
        $guard = 0;
        $id = $catId;
        while ($id > 0 && isset($cats[$id]) && $guard++ < 20) {
            array_unshift($parts, (string)$cats[$id]['name']);
            $id = (int)$cats[$id]['parent_id'];
        }
        return implode(' / ', $parts);
    }

    /** @return list<int> */
    private static function parseIdList(string $csv): array
    {
        $out = [];
        foreach (explode(',', $csv) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $id = (int)$part;
            if ($id > 0) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }
}
