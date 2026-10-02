<?php
declare(strict_types=1);

/**
 * Live leftovers from sticker ins (QTYU_IN - QTYU_OUT), excluding virtual stocks.
 */
final class StockLeftovers
{
    /**
     * @return list<array{
     *   id_good:int,name:string,qtyu:float,stock_min:float,stock_max:float,
     *   unit:string,category:string
     * }>
     */
    public static function summarize(int $idGood = 0, bool $showAll = false): array
    {
        if (!Database::tableExists('base_good_sticker_ins') || !Database::tableExists('base_products')) {
            return [];
        }

        $virtIds = [];
        if (Database::tableExists('base_stocks')) {
            $st = Database::pdo()->query('SELECT ID FROM base_stocks WHERE TYPE = 2');
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                $virtIds[] = (int)$id;
            }
        }

        $sql = 'SELECT s.ID_GOOD AS id_good,
                       SUM(s.QTYU_IN - s.QTYU_OUT) AS qtyu
                FROM base_good_sticker_ins s
                WHERE 1=1';
        $params = [];
        if ($virtIds !== []) {
            $ph = implode(',', array_fill(0, count($virtIds), '?'));
            $sql .= " AND s.ID_STOCK NOT IN ($ph)";
            $params = array_merge($params, $virtIds);
        }
        if ($idGood > 0) {
            $sql .= ' AND s.ID_GOOD = ?';
            $params[] = $idGood;
        }
        $sql .= ' GROUP BY s.ID_GOOD';

        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $productIds = [];
        foreach ($rows as $r) {
            $productIds[] = (int)$r['id_good'];
        }
        $products = self::loadProducts($productIds);

        $out = [];
        foreach ($rows as $r) {
            $gid = (int)$r['id_good'];
            $qtyu = round((float)$r['qtyu'], 3);
            $p = $products[$gid] ?? null;
            if (!$p) {
                continue;
            }
            if (!$showAll && $qtyu <= 0 && (float)$p['stock_min'] <= 0) {
                continue;
            }
            $out[] = [
                'id_good' => $gid,
                'name' => (string)$p['name'],
                'qtyu' => $qtyu,
                'stock_min' => (float)$p['stock_min'],
                'stock_max' => (float)$p['stock_max'],
                'unit' => (string)$p['unit'],
                'category' => (string)$p['category'],
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,array{name:string,stock_min:float,stock_max:float,unit:string,category:string}>
     */
    private static function loadProducts(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $joinUnit = Database::tableExists('base_units')
            ? 'LEFT JOIN base_units u ON u.ID = p.unit_use_id'
            : '';
        $unitSel = Database::tableExists('base_units') ? 'COALESCE(u.NAME, \'\')' : '\'\'';
        $joinCat = Database::tableExists('base_categories')
            ? 'LEFT JOIN base_categories c ON c.id = p.category_id'
            : '';
        $catSel = Database::tableExists('base_categories') ? 'COALESCE(c.name, \'\')' : '\'\'';

        $sql = "SELECT p.id, p.name, p.stock_min, p.stock_max,
                       $unitSel AS unit_name, $catSel AS category_name
                FROM base_products p
                $joinUnit
                $joinCat
                WHERE p.id IN ($ph)";
        $st = Database::pdo()->prepare($sql);
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['id']] = [
                'name' => (string)$r['name'],
                'stock_min' => (float)$r['stock_min'],
                'stock_max' => (float)$r['stock_max'],
                'unit' => (string)$r['unit_name'],
                'category' => (string)$r['category_name'],
            ];
        }
        return $out;
    }
}
