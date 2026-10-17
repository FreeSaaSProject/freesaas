<?php
declare(strict_types=1);

/** Storefront catalog helpers (categories + product visibility per host root). */
final class SitesCatalog
{
    /** @return list<int>|null null = no folder restriction */
    public static function allowedCategoryIds(): ?array
    {
        $roots = [];
        if (class_exists(SitesHostContext::class)) {
            $roots = SitesHostContext::catalogRootIds();
        }
        if ($roots === [] || !class_exists(CategoriesRepository::class)) {
            return null;
        }
        $allowed = [];
        foreach ($roots as $root) {
            foreach (CategoriesRepository::descendantIds($root) as $cid) {
                $cid = (int)$cid;
                if ($cid > 0 && !in_array($cid, $allowed, true)) {
                    $allowed[] = $cid;
                }
            }
        }
        return $allowed;
    }

    /**
     * @return list<array{id:int,name:string,href:string,current:bool}>
     */
    public static function categoryNav(?int $currentId = null): array
    {
        if (!class_exists('CategoriesRepository', false)) {
            return [];
        }
        $allowed = self::allowedCategoryIds();
        $items = [];
        $items[] = [
            'id' => 0,
            'name' => t('sites.shop.nav_all'),
            'href' => '/shop',
            'current' => $currentId === null || $currentId < 1,
        ];
        foreach (CategoriesRepository::listForStorefront(null, $allowed) as $cat) {
            $id = (int)($cat['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'name' => (string)($cat['name'] ?? ('#' . $id)),
                'href' => '/shop/c/' . $id,
                'current' => $currentId === $id,
            ];
        }
        return $items;
    }

    /**
     * @param array{category?:int|null,limit?:int,offset?:int,order?:string} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function listProducts(array $opts = []): array
    {
        if (!class_exists(ProductsRepository::class)) {
            return ['items' => [], 'total' => 0];
        }
        $allowed = self::allowedCategoryIds();
        if ($allowed !== null) {
            $opts['category_ids'] = $allowed;
        }
        $cat = isset($opts['category']) ? (int)$opts['category'] : 0;
        if ($cat > 0 && $allowed !== null && !in_array($cat, $allowed, true)) {
            return ['items' => [], 'total' => 0];
        }
        return ProductsRepository::listForStorefront($opts);
    }

    public static function isProductVisible(?array $product): bool
    {
        if (!class_exists(ProductsRepository::class)) {
            return false;
        }
        return ProductsRepository::isStorefrontVisible($product, self::allowedCategoryIds());
    }

    public static function categoryAllowed(int $categoryId): bool
    {
        if ($categoryId < 1) {
            return self::allowedCategoryIds() === null;
        }
        $allowed = self::allowedCategoryIds();
        if ($allowed === null) {
            return true;
        }
        return in_array($categoryId, $allowed, true);
    }

    /**
     * Storefront price of a product (cart + public channels).
     * PRICE table: strike price2/price, online price3 when lower.
     *
     * @param array<string,mixed> $product
     * @return array{price:float,original:float}
     */
    public static function onlinePrice(array $product): array
    {
        $price = (float)($product['price'] ?? 0);
        $original = (float)($product['price2'] ?? 0);
        $price3 = (float)($product['price3'] ?? 0);
        if ($price3 > 0 && $price3 < $price) {
            if ($original <= $price) {
                $original = $price;
            }
            $price = $price3;
        } elseif ($original <= $price) {
            $original = $price;
        }
        return ['price' => $price, 'original' => $original];
    }

    /**
     * @return array{id:int,name:string,price:float}|null
     */
    public static function addToCartFromPost(): ?array
    {
        $pid = (int)($_POST['product_id'] ?? 0);
        if ($pid < 1 || !class_exists('ProductsRepository', false)) {
            return null;
        }
        $p = ProductsRepository::find($pid);
        if (!self::isProductVisible($p)) {
            return null;
        }
        ['price' => $price, 'original' => $original] = self::onlinePrice($p);
        SitesCart::add($pid, (string)$p['name'], $price, $original);
        flash_set('ok', t('sites.cart.added'));
        return [
            'id' => $pid,
            'name' => (string)$p['name'],
            'price' => $price,
        ];
    }
}
