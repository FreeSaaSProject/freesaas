<?php
declare(strict_types=1);

final class ProductsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'p.id',
            'name' => 'p.name',
            'category' => 'p.category',
            'num' => 'p.num',
            'price' => 'p.price',
            'price2' => 'p.price2',
            'price3' => 'p.price3',
            'price4' => 'p.price4',
            'price5' => 'p.price5',
            'enabled' => 'p.enabled',
            'is_material' => 'p.is_material',
            'time_sec' => 'p.time_sec',
        ];
    }

    /**
     * @param array{
     *   q?:string,
     *   category?:int|null,
     *   folder?:int|null,
     *   enabled?:string|null,
     *   is_material?:string|null,
     *   order?:string,
     *   limit?:int,
     *   offset?:int
     * } $opts
     *   folder: browse mode — category match + dop_cat (incl. 0 = root orphans)
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_products')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.name LIKE ? OR p.name_price LIKE ? OR p.onec_id LIKE ? OR CAST(p.id AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
        }
        if (array_key_exists('folder', $opts) && $opts['folder'] !== null) {
            $fid = (int)$opts['folder'];
            if ($fid > 0) {
                $where[] = '(p.category = ? OR p.dop_cat LIKE ?)';
                $params[] = $fid;
                $params[] = '%#' . $fid . '#%';
            } else {
                $where[] = 'p.category = 0';
            }
        } elseif (!empty($opts['category'])) {
            $where[] = 'p.category = ?';
            $params[] = (int)$opts['category'];
        }
        if (array_key_exists('enabled', $opts) && $opts['enabled'] !== null && $opts['enabled'] !== '') {
            $where[] = 'p.enabled = ?';
            $params[] = (int)$opts['enabled'];
        }
        if (array_key_exists('is_material', $opts) && $opts['is_material'] !== null && $opts['is_material'] !== '') {
            $where[] = 'p.is_material = ?';
            $params[] = (int)$opts['is_material'];
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_products p WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'p.category ASC, p.num ASC, p.name ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));

        $joinCat = Database::tableExists('base_categories')
            ? 'LEFT JOIN base_categories c ON c.id = p.category'
            : '';
        $catName = $joinCat !== '' ? 'c.name AS category_name' : 'NULL AS category_name';

        $sql = "SELECT p.id, p.category, p.name, p.name_price, p.enabled, p.num,
                       p.price, p.price2, p.price3, p.price4, p.price5,
                       p.is_material, p.time_sec, p.time_net, p.onec_id, $catName
                FROM base_products p
                $joinCat
                WHERE $sqlWhere
                ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /** @return array<int,string> */
    public static function names(bool $enabledOnly = true): array
    {
        if (!Database::tableExists('base_products')) {
            return [];
        }
        $sql = 'SELECT id, name FROM base_products';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY category ASC, num ASC, name ASC';
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['id']] = (string)$r['name'];
        }
        return $out;
    }

    /** @return array<int,float> id => price */
    public static function priceMap(bool $enabledOnly = true): array
    {
        if (!Database::tableExists('base_products')) {
            return [];
        }
        $sql = 'SELECT id, price FROM base_products';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['id']] = (float)$r['price'];
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_products')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, category, name, name_price, description, enabled, num,
                    price, price2, price3, price4, price5,
                    is_material, type_id, time_sec, time_net, descr_proc,
                    unit_id, unit_use_id, supplier_id, stock_min, stock_max,
                    can_be_sold, can_be_executed, is_status_card, hide_in_history,
                    onec_id, dop_cat, amount_done, last_admin_id, last_admin_at
             FROM base_products WHERE id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function setEnabled(int $id, bool $enabled): void
    {
        if ($id < 1 || !Database::tableExists('base_products')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_products SET enabled = ?, last_admin_id = ?, last_admin_at = ? WHERE id = ?'
        );
        $st->execute([$enabled ? 1 : 0, Auth::userId(), time(), $id]);
    }

    /**
     * Which price tier columns vary inside a folder (or across catalog for search).
     * With fewer than 2 products: show only `price`.
     * With 2+: show each tier where min != max.
     *
     * @return list<string> e.g. ['price','price3']
     */
    public static function varyingPriceColumns(?int $folderId, bool $globalSearch = false): array
    {
        if (!Database::tableExists('base_products')) {
            return [];
        }
        $where = ['1=1'];
        $params = [];
        if ($globalSearch) {
            // all products
        } elseif ($folderId !== null) {
            $fid = (int)$folderId;
            if ($fid > 0) {
                $where[] = '(category = ? OR dop_cat LIKE ?)';
                $params[] = $fid;
                $params[] = '%#' . $fid . '#%';
            } else {
                $where[] = 'category = 0';
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $st = Database::pdo()->prepare(
            "SELECT COUNT(*) AS cnt,
                    MIN(price) AS min1, MAX(price) AS max1,
                    MIN(price2) AS min2, MAX(price2) AS max2,
                    MIN(price3) AS min3, MAX(price3) AS max3,
                    MIN(price4) AS min4, MAX(price4) AS max4,
                    MIN(price5) AS min5, MAX(price5) AS max5
             FROM base_products WHERE $sqlWhere"
        );
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $cnt = (int)($row['cnt'] ?? 0);
        if ($cnt < 1) {
            return [];
        }
        if ($cnt === 1) {
            return ['price'];
        }
        $cols = [];
        $map = [
            'price' => ['min1', 'max1'],
            'price2' => ['min2', 'max2'],
            'price3' => ['min3', 'max3'],
            'price4' => ['min4', 'max4'],
            'price5' => ['min5', 'max5'],
        ];
        foreach ($map as $col => [$minK, $maxK]) {
            $min = (float)($row[$minK] ?? 0);
            $max = (float)($row[$maxK] ?? 0);
            if (abs($min - $max) > 0.00001) {
                $cols[] = $col;
            }
        }
        return $cols;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('catalog.product.err.name_required'));
        }
        return [
            'category' => max(0, (int)($post['category'] ?? 0)),
            'name' => mb_substr($name, 0, 255),
            'name_price' => mb_substr(trim((string)($post['name_price'] ?? '')), 0, 200),
            'description' => trim((string)($post['description'] ?? '')),
            'enabled' => !empty($post['enabled']) ? 1 : 0,
            'num' => (int)($post['num'] ?? 0),
            'price' => (float)($post['price'] ?? 0),
            'price2' => (float)($post['price2'] ?? 0),
            'price3' => (float)($post['price3'] ?? 0),
            'price4' => max(0.0, (float)($post['price4'] ?? 0)),
            'price5' => max(0.0, (float)($post['price5'] ?? 0)),
            'is_material' => !empty($post['is_material']) ? 1 : 0,
            'type_id' => max(0, (int)($post['type_id'] ?? 0)),
            'time_sec' => max(0, (int)($post['time_sec'] ?? 0)),
            'time_net' => max(0, (int)($post['time_net'] ?? 0)),
            'descr_proc' => trim((string)($post['descr_proc'] ?? '')),
            'unit_id' => max(0, (int)($post['unit_id'] ?? 0)),
            'unit_use_id' => max(0, (int)($post['unit_use_id'] ?? 0)),
            'supplier_id' => max(0, (int)($post['supplier_id'] ?? 0)),
            'stock_min' => max(0, (int)($post['stock_min'] ?? 0)),
            'stock_max' => max(0, (int)($post['stock_max'] ?? 0)),
            'can_be_sold' => !empty($post['can_be_sold']) ? 1 : 0,
            'can_be_executed' => !empty($post['can_be_executed']) ? 1 : 0,
            'is_status_card' => !empty($post['is_status_card']) ? 1 : 0,
            'hide_in_history' => !empty($post['hide_in_history']) ? 1 : 0,
            'onec_id' => mb_substr(trim((string)($post['onec_id'] ?? '')), 0, 40),
            'dop_cat' => mb_substr(trim((string)($post['dop_cat'] ?? '')), 0, 255),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $adminId = Auth::userId();
        $now = time();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_products (
                category, name, name_price, description, enabled, num,
                price, price2, price3, price4, price5,
                is_material, type_id, time_sec, time_net, descr_proc,
                unit_id, unit_use_id, supplier_id, stock_min, stock_max,
                can_be_sold, can_be_executed, is_status_card, hide_in_history,
                onec_id, dop_cat, amount_done, last_admin_id, last_admin_at
             ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, 0, ?, ?
             )'
        );
        $st->execute([
            $data['category'],
            $data['name'],
            $data['name_price'],
            $data['description'] !== '' ? $data['description'] : null,
            $data['enabled'],
            $data['num'],
            $data['price'],
            $data['price2'],
            $data['price3'],
            $data['price4'],
            $data['price5'],
            $data['is_material'],
            $data['type_id'],
            $data['time_sec'],
            $data['time_net'],
            $data['descr_proc'] !== '' ? $data['descr_proc'] : null,
            $data['unit_id'],
            $data['unit_use_id'],
            $data['supplier_id'],
            $data['stock_min'],
            $data['stock_max'],
            $data['can_be_sold'],
            $data['can_be_executed'],
            $data['is_status_card'],
            $data['hide_in_history'],
            $data['onec_id'],
            $data['dop_cat'],
            $adminId,
            $now,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid product id');
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_products SET
             category = ?, name = ?, name_price = ?, description = ?, enabled = ?, num = ?,
             price = ?, price2 = ?, price3 = ?, price4 = ?, price5 = ?,
             is_material = ?, type_id = ?, time_sec = ?, time_net = ?, descr_proc = ?,
             unit_id = ?, unit_use_id = ?, supplier_id = ?, stock_min = ?, stock_max = ?,
             can_be_sold = ?, can_be_executed = ?, is_status_card = ?, hide_in_history = ?,
             onec_id = ?, dop_cat = ?, last_admin_id = ?, last_admin_at = ?
             WHERE id = ?'
        );
        $st->execute([
            $data['category'],
            $data['name'],
            $data['name_price'],
            $data['description'] !== '' ? $data['description'] : null,
            $data['enabled'],
            $data['num'],
            $data['price'],
            $data['price2'],
            $data['price3'],
            $data['price4'],
            $data['price5'],
            $data['is_material'],
            $data['type_id'],
            $data['time_sec'],
            $data['time_net'],
            $data['descr_proc'] !== '' ? $data['descr_proc'] : null,
            $data['unit_id'],
            $data['unit_use_id'],
            $data['supplier_id'],
            $data['stock_min'],
            $data['stock_max'],
            $data['can_be_sold'],
            $data['can_be_executed'],
            $data['is_status_card'],
            $data['hide_in_history'],
            $data['onec_id'],
            $data['dop_cat'],
            Auth::userId(),
            time(),
            $id,
        ]);
    }

    /** Apply component rollup prices/times to product. */
    public static function applyComponentTotals(int $id): bool
    {
        $sum = ProductRelationsRepository::sumFromComponents($id);
        if ($sum === null) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_products SET
             price = ?, price2 = ?, price3 = ?, price4 = ?, price5 = ?,
             time_sec = ?, time_net = ?, last_admin_id = ?, last_admin_at = ?
             WHERE id = ?'
        );
        $st->execute([
            $sum['price'],
            $sum['price2'],
            $sum['price3'],
            $sum['price4'],
            $sum['price5'],
            $sum['time_sec'],
            $sum['time_net'],
            Auth::userId(),
            time(),
            $id,
        ]);
        return true;
    }

    public static function countByUnitId(int $unitId): int
    {
        if ($unitId < 1 || !Database::tableExists('base_products')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_products WHERE unit_id = ? OR unit_use_id = ?'
        );
        $st->execute([$unitId, $unitId]);
        return (int)$st->fetchColumn();
    }

    public static function countBySupplierId(int $supplierId): int
    {
        if ($supplierId < 1 || !Database::tableExists('base_products')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_products WHERE supplier_id = ?'
        );
        $st->execute([$supplierId]);
        return (int)$st->fetchColumn();
    }

    public static function countByProcedureTypeId(int $typeId): int
    {
        if ($typeId < 1 || !Database::tableExists('base_products')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_products WHERE type_id = ?'
        );
        $st->execute([$typeId]);
        return (int)$st->fetchColumn();
    }
}
