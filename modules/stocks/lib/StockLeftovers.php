<?php
declare(strict_types=1);

/**
 * Live leftovers from sticker ins (QTYU_IN - QTYU_OUT), plus optional
 * future demand (events × norms) and monthly in/out history.
 */
final class StockLeftovers
{
    /**
     * @param array{
     *   id_good?:int,
     *   show_all?:bool,
     *   months?:int,
     *   show_cat?:int|bool
     * } $opts
     * @return list<array{
     *   id_good:int,name:string,qtyu:float,stock_min:float,stock_max:float,
     *   unit:string,category:string,category_id:int,
     *   need_future:float,months:list<array{label:string,in:float,out:float}>
     * }>
     */
    public static function summarize(array $opts = []): array
    {
        if (!Database::tableExists('base_good_sticker_ins') || !Database::tableExists('base_products')) {
            return [];
        }

        $idGood = max(0, (int)($opts['id_good'] ?? 0));
        $showAll = !empty($opts['show_all']);
        $months = max(0, min(12, (int)($opts['months'] ?? 6)));
        $showCat = (int)($opts['show_cat'] ?? 0);

        $sql = 'SELECT s.ID_GOOD AS id_good,
                       SUM(s.QTYU_IN - s.QTYU_OUT) AS qtyu
                FROM base_good_sticker_ins s
                WHERE 1=1';
        $params = [];
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
        $needFuture = self::futureNeedByGood($idGood);
        $monthWindows = self::monthWindows($months);
        $monthMoves = $months > 0 ? self::monthlyMoves(array_keys($products) ?: $productIds, $monthWindows) : [];

        $out = [];
        foreach ($rows as $r) {
            $gid = (int)$r['id_good'];
            $qtyu = round((float)$r['qtyu'], 3);
            $p = $products[$gid] ?? null;
            if (!$p) {
                continue;
            }
            $catId = (int)$p['category_id'];
            if ($showCat > 0 && $catId !== $showCat) {
                continue;
            }
            $need = (float)($needFuture[$gid] ?? 0);
            $min = (float)$p['stock_min'];
            if (!$showAll && $qtyu <= 0 && $min <= 0 && $need <= 0) {
                continue;
            }
            $monthRows = [];
            foreach ($monthWindows as $i => $win) {
                $key = $gid . ':' . $i;
                $mv = $monthMoves[$key] ?? ['in' => 0.0, 'out' => 0.0];
                $monthRows[] = [
                    'label' => $win['label'],
                    'in' => round((float)$mv['in'], 3),
                    'out' => round((float)$mv['out'], 3),
                ];
            }
            $out[] = [
                'id_good' => $gid,
                'name' => (string)$p['name'],
                'qtyu' => $qtyu,
                'stock_min' => $min,
                'stock_max' => (float)$p['stock_max'],
                'unit' => (string)$p['unit'],
                'category' => (string)$p['category'],
                'category_id' => $catId,
                'need_future' => round($need, 3),
                'months' => $monthRows,
            ];
        }

        usort($out, static function (array $a, array $b): int {
            $ca = strcasecmp($a['category'], $b['category']);
            if ($ca !== 0) {
                return $ca;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * @return array<int,string> category_id => name (categories that appear in leftovers)
     */
    public static function categoryOptions(): array
    {
        if (!Database::tableExists('base_good_sticker_ins') || !Database::tableExists('base_products')) {
            return [];
        }
        $joinCat = Database::tableExists('base_categories')
            ? 'LEFT JOIN base_categories c ON c.id = p.category'
            : '';
        $catSel = Database::tableExists('base_categories') ? 'COALESCE(c.name, \'\')' : '\'\'';
        $sql = "SELECT DISTINCT p.category AS cat_id, $catSel AS cat_name
                FROM base_good_sticker_ins s
                INNER JOIN base_products p ON p.id = s.ID_GOOD
                $joinCat
                ORDER BY cat_name ASC";
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $cid = (int)$r['cat_id'];
            if ($cid < 1) {
                continue;
            }
            $out[$cid] = (string)$r['cat_name'] !== '' ? (string)$r['cat_name'] : ('#' . $cid);
        }
        return $out;
    }

    /**
     * Upcoming calendar events × product.type_id × base_good_defaults (QTYU2 × item qty).
     *
     * @return array<int,float> id_good => need qty
     */
    private static function futureNeedByGood(int $filterGood = 0): array
    {
        if (!Database::tableExists('base_events')
            || !Database::tableExists('base_event_items')
            || !Database::tableExists('base_good_defaults')
            || !Database::tableExists('base_products')
        ) {
            return [];
        }

        $now = time();
        $sql = 'SELECT ei.ID_GOOD AS event_good, ei.QTY AS qty, p.type_id AS type_id
                FROM base_events e
                INNER JOIN base_event_items ei ON ei.ID_EVENT = e.ID
                INNER JOIN base_products p ON p.id = ei.ID_GOOD
                WHERE e.DTSTART > ?
                  AND (e.IS_BLOCK = 0 OR e.IS_BLOCK IS NULL)
                  AND p.type_id > 0';
        $params = [$now];
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $eventRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($eventRows === []) {
            return [];
        }

        $typeQty = [];
        foreach ($eventRows as $r) {
            $typeId = (int)$r['type_id'];
            $qty = max(1, (int)$r['qty']);
            $typeQty[$typeId] = ($typeQty[$typeId] ?? 0) + $qty;
        }

        $typeIds = array_keys($typeQty);
        $ph = implode(',', array_fill(0, count($typeIds), '?'));
        $stN = Database::pdo()->prepare(
            "SELECT ID_PROC, ID_GOOD, QTYU2 FROM base_good_defaults WHERE ID_PROC IN ($ph)"
        );
        $stN->execute($typeIds);
        $need = [];
        foreach ($stN->fetchAll(PDO::FETCH_ASSOC) ?: [] as $n) {
            $gid = (int)$n['ID_GOOD'];
            if ($filterGood > 0 && $gid !== $filterGood) {
                continue;
            }
            $proc = (int)$n['ID_PROC'];
            $mult = (int)($typeQty[$proc] ?? 0);
            if ($mult < 1) {
                continue;
            }
            $need[$gid] = ($need[$gid] ?? 0.0) + ((float)$n['QTYU2'] * $mult);
        }
        return $need;
    }

    /**
     * @return list<array{start:int,end:int,label:string}>
     */
    private static function monthWindows(int $months): array
    {
        if ($months < 1) {
            return [];
        }
        $out = [];
        $cursor = (int)strtotime(date('Y-m-01 00:00:00'));
        for ($i = 0; $i < $months; $i++) {
            $start = (int)strtotime('-' . $i . ' months', $cursor);
            $end = (int)strtotime('+1 month', $start);
            $out[] = [
                'start' => $start,
                'end' => $end,
                'label' => date('m.Y', $start),
            ];
        }
        return $out;
    }

    /**
     * @param list<int> $goodIds
     * @param list<array{start:int,end:int,label:string}> $windows
     * @return array<string,array{in:float,out:float}>
     */
    private static function monthlyMoves(array $goodIds, array $windows): array
    {
        $goodIds = array_values(array_unique(array_filter(array_map('intval', $goodIds))));
        if ($goodIds === [] || $windows === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($goodIds), '?'));
        $minStart = min(array_column($windows, 'start'));
        $maxEnd = max(array_column($windows, 'end'));

        $ins = [];
        $stIn = Database::pdo()->prepare(
            "SELECT ID_GOOD, DT_CREATE, QTYU_IN FROM base_good_sticker_ins
             WHERE ID_GOOD IN ($ph) AND DT_CREATE >= ? AND DT_CREATE < ?"
        );
        $stIn->execute(array_merge($goodIds, [$minStart, $maxEnd]));
        foreach ($stIn->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $ins[] = [(int)$r['ID_GOOD'], (int)$r['DT_CREATE'], (float)$r['QTYU_IN']];
        }

        $outs = [];
        if (Database::tableExists('base_good_sticker_outs')) {
            $stOut = Database::pdo()->prepare(
                "SELECT ID_GOOD, DT_CREATE, QTYU FROM base_good_sticker_outs
                 WHERE ID_GOOD IN ($ph) AND DT_CREATE >= ? AND DT_CREATE < ?"
            );
            $stOut->execute(array_merge($goodIds, [$minStart, $maxEnd]));
            foreach ($stOut->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $outs[] = [(int)$r['ID_GOOD'], (int)$r['DT_CREATE'], (float)$r['QTYU']];
            }
        }

        $result = [];
        foreach ($windows as $i => $win) {
            foreach ($goodIds as $gid) {
                $result[$gid . ':' . $i] = ['in' => 0.0, 'out' => 0.0];
            }
            foreach ($ins as [$gid, $dt, $qty]) {
                if ($dt >= $win['start'] && $dt < $win['end']) {
                    $result[$gid . ':' . $i]['in'] += $qty;
                }
            }
            foreach ($outs as [$gid, $dt, $qty]) {
                if ($dt >= $win['start'] && $dt < $win['end']) {
                    $result[$gid . ':' . $i]['out'] += $qty;
                }
            }
        }
        return $result;
    }

    /**
     * @param list<int> $ids
     * @return array<int,array{name:string,stock_min:float,stock_max:float,unit:string,category:string,category_id:int}>
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
            ? 'LEFT JOIN base_categories c ON c.id = p.category'
            : '';
        $catSel = Database::tableExists('base_categories') ? 'COALESCE(c.name, \'\')' : '\'\'';

        $sql = "SELECT p.id, p.name, p.stock_min, p.stock_max, p.category AS category_id,
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
                'category_id' => (int)$r['category_id'],
            ];
        }
        return $out;
    }
}
