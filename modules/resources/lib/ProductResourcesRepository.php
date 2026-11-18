<?php
declare(strict_types=1);

final class ProductResourcesRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_product_resources');
    }

    /**
     * @return list<array{id:int,product_id:int,resource_id:int,qty_needed:int,resource_name?:string,resource_qty?:int,resource_enabled?:int}>
     */
    public static function forProduct(int $productId): array
    {
        if ($productId < 1 || !self::tableReady()) {
            return [];
        }
        $sql = 'SELECT pr.ID AS id, pr.ID_PRODUCT AS product_id, pr.ID_RESOURCE AS resource_id,
                       pr.QTY_NEEDED AS qty_needed';
        if (Database::tableExists('base_resources')) {
            $sql .= ', r.NAME AS resource_name, r.QTY AS resource_qty, r.ENABLED AS resource_enabled
                     FROM base_product_resources pr
                     LEFT JOIN base_resources r ON r.ID = pr.ID_RESOURCE
                     WHERE pr.ID_PRODUCT = ?
                     ORDER BY pr.ID ASC';
        } else {
            $sql .= ' FROM base_product_resources pr WHERE pr.ID_PRODUCT = ? ORDER BY pr.ID ASC';
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute([$productId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Aggregate requirements for many products (sum qty_needed per resource).
     *
     * @param list<int> $productIds
     * @return list<array{resource_id:int,qty_needed:int,qty:int,name:string,enabled:int}>
     */
    public static function requirementsForProducts(array $productIds): array
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0));
        if ($productIds === [] || !self::tableReady() || !Database::tableExists('base_resources')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $st = Database::pdo()->prepare(
            "SELECT pr.ID_RESOURCE AS resource_id,
                    SUM(pr.QTY_NEEDED) AS qty_needed,
                    r.QTY AS qty,
                    r.NAME AS name,
                    r.ENABLED AS enabled
             FROM base_product_resources pr
             INNER JOIN base_resources r ON r.ID = pr.ID_RESOURCE
             WHERE pr.ID_PRODUCT IN ($placeholders) AND r.ENABLED = 1
             GROUP BY pr.ID_RESOURCE, r.QTY, r.NAME, r.ENABLED"
        );
        $st->execute($productIds);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rid = (int)($row['resource_id'] ?? 0);
            $need = max(1, (int)($row['qty_needed'] ?? 1));
            $qty = max(1, (int)($row['qty'] ?? 1));
            if ($rid < 1) {
                continue;
            }
            $out[] = [
                'resource_id' => $rid,
                'qty_needed' => $need,
                'qty' => $qty,
                'name' => (string)($row['name'] ?? ('#' . $rid)),
                'enabled' => (int)($row['enabled'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Replace product→resource links from POST checkboxes / qty map.
     *
     * @param array<string,mixed> $post expects resource_ids[] and optional resource_qty[id]
     */
    public static function saveFromPost(int $productId, array $post): void
    {
        if ($productId < 1 || !self::tableReady()) {
            return;
        }
        $rawIds = $post['resource_ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [];
        }
        $qtyMap = is_array($post['resource_qty'] ?? null) ? $post['resource_qty'] : [];
        $links = [];
        foreach ($rawIds as $rid) {
            $rid = (int)$rid;
            if ($rid < 1) {
                continue;
            }
            $need = (int)($qtyMap[$rid] ?? $qtyMap[(string)$rid] ?? 1);
            if ($need < 1) {
                $need = 1;
            }
            if ($need > 999) {
                $need = 999;
            }
            $links[$rid] = $need;
        }
        self::replaceForProduct($productId, $links);
    }

    /**
     * @param array<int,int> $resourceIdToQtyNeeded
     */
    public static function replaceForProduct(int $productId, array $resourceIdToQtyNeeded): void
    {
        if ($productId < 1 || !self::tableReady()) {
            return;
        }
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM base_product_resources WHERE ID_PRODUCT = ?')->execute([$productId]);
        if ($resourceIdToQtyNeeded === []) {
            return;
        }
        $st = $pdo->prepare(
            'INSERT INTO base_product_resources (ID_PRODUCT, ID_RESOURCE, QTY_NEEDED)
             VALUES (?, ?, ?)'
        );
        foreach ($resourceIdToQtyNeeded as $rid => $need) {
            $rid = (int)$rid;
            $need = max(1, (int)$need);
            if ($rid < 1) {
                continue;
            }
            if (class_exists(ResourcesRepository::class, false) && ResourcesRepository::find($rid) === null) {
                continue;
            }
            $st->execute([$productId, $rid, $need]);
        }
    }

    /**
     * @param list<int> $resourceIds
     */
    public static function deleteByResourceIds(array $resourceIds): void
    {
        $resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds), static fn (int $id): bool => $id > 0)));
        if ($resourceIds === [] || !self::tableReady()) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($resourceIds), '?'));
        Database::pdo()->prepare("DELETE FROM base_product_resources WHERE ID_RESOURCE IN ($placeholders)")
            ->execute($resourceIds);
    }

    public static function countForResource(int $resourceId): int
    {
        if ($resourceId < 1 || !self::tableReady()) {
            return 0;
        }
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_product_resources WHERE ID_RESOURCE = ?');
        $st->execute([$resourceId]);
        return (int)$st->fetchColumn();
    }
}
