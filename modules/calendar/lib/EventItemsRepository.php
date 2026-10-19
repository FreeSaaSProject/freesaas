<?php
declare(strict_types=1);

/** Planned basket lines for a calendar visit (base_event_items). */
final class EventItemsRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_event_items');
    }

    /**
     * @return list<array{id:int,id_event:int,id_good:int,price:float,qty:int,pos:int}>
     */
    public static function listByEvent(int $eventId): array
    {
        if ($eventId < 1 || !self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_EVENT AS id_event, ID_GOOD AS id_good,
                    PRICE AS price, QTY AS qty, POS AS pos
             FROM base_event_items
             WHERE ID_EVENT = ?
             ORDER BY POS ASC, ID ASC'
        );
        $st->execute([$eventId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'id_event' => (int)$row['id_event'],
                'id_good' => (int)$row['id_good'],
                'price' => (float)$row['price'],
                'qty' => max(1, (int)$row['qty']),
                'pos' => (int)$row['pos'],
            ];
        }
        return $out;
    }

    /** @return list<int> */
    public static function goodIds(int $eventId): array
    {
        $ids = [];
        foreach (self::listByEvent($eventId) as $item) {
            $ids[] = $item['id_good'];
        }
        return $ids;
    }

    /** @return array<int,float> goodId => unit price */
    public static function priceMap(int $eventId): array
    {
        $map = [];
        foreach (self::listByEvent($eventId) as $item) {
            $map[$item['id_good']] = $item['price'];
        }
        return $map;
    }

    /** @return array<int,int> goodId => qty */
    public static function qtyMap(int $eventId): array
    {
        $map = [];
        foreach (self::listByEvent($eventId) as $item) {
            $map[$item['id_good']] = $item['qty'];
        }
        return $map;
    }

    public static function totalSum(int $eventId): float
    {
        $sum = 0.0;
        foreach (self::listByEvent($eventId) as $item) {
            $sum += $item['price'] * $item['qty'];
        }
        return $sum;
    }

    /**
     * Replace all lines for an event. Empty list clears the basket.
     *
     * @param list<array{id_good:int,price?:float,qty?:int,pos?:int}> $items
     */
    public static function replaceItems(int $eventId, array $items): void
    {
        if ($eventId < 1 || !self::tableReady()) {
            return;
        }
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM base_event_items WHERE ID_EVENT = ?')->execute([$eventId]);

        $ins = $pdo->prepare(
            'INSERT INTO base_event_items (ID_EVENT, ID_GOOD, PRICE, QTY, POS) VALUES (?, ?, ?, ?, ?)'
        );
        $pos = 0;
        $seen = [];
        foreach ($items as $raw) {
            $gid = (int)($raw['id_good'] ?? 0);
            if ($gid < 1 || isset($seen[$gid])) {
                continue;
            }
            $seen[$gid] = true;
            $price = max(0.0, (float)($raw['price'] ?? 0));
            $qty = max(1, (int)($raw['qty'] ?? 1));
            $linePos = isset($raw['pos']) ? max(0, (int)$raw['pos']) : $pos;
            $ins->execute([$eventId, $gid, round($price, 2), $qty, $linePos]);
            $pos++;
        }
    }

    /** @param array<int,float> $overrides goodId => unit price */
    public static function updatePrices(int $eventId, array $overrides): void
    {
        if ($eventId < 1 || $overrides === [] || !self::tableReady()) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_event_items SET PRICE = ? WHERE ID_EVENT = ? AND ID_GOOD = ?'
        );
        foreach ($overrides as $gid => $price) {
            $gid = (int)$gid;
            if ($gid < 1) {
                continue;
            }
            $st->execute([round(max(0.0, (float)$price), 2), $eventId, $gid]);
        }
    }

    public static function deleteByEvent(int $eventId): void
    {
        if ($eventId < 1 || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM base_event_items WHERE ID_EVENT = ?')->execute([$eventId]);
    }

    /**
     * Frequently used goods for picker (event items + recent procedures).
     * When $operatorId > 0 and booking gate is available, only services that operator can perform.
     *
     * @param list<int> $keepProductIds always include (already on the event)
     * @return list<array{id:int,name:string,price:float,uses:int,time_sec:int,type_id:int}>
     */
    public static function frequentGoods(int $limit = 12, int $operatorId = 0, int $dayStart = 0, array $keepProductIds = []): array
    {
        $limit = max(1, min(40, $limit));
        $counts = [];
        if (self::tableReady()) {
            $rows = Database::pdo()->query(
                'SELECT ID_GOOD AS id_good, COUNT(*) AS c
                 FROM base_event_items
                 GROUP BY ID_GOOD
                 ORDER BY c DESC
                 LIMIT ' . (int)max($limit * 3, 40)
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $gid = (int)$row['id_good'];
                if ($gid > 0) {
                    $counts[$gid] = (int)$row['c'];
                }
            }
        }
        if (Database::tableExists('base_procedures') && count($counts) < $limit * 3) {
            $need = max($limit * 3, 40);
            $rows = Database::pdo()->query(
                'SELECT ID_GOOD AS id_good, COUNT(*) AS c
                 FROM base_procedures
                 WHERE ENABLED = 1 AND DTSTART > ' . (time() - 86400 * 180) . '
                 GROUP BY ID_GOOD
                 ORDER BY c DESC
                 LIMIT ' . (int)$need
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $gid = (int)$row['id_good'];
                if ($gid < 1) {
                    continue;
                }
                $counts[$gid] = ($counts[$gid] ?? 0) + (int)$row['c'];
            }
        }
        arsort($counts, SORT_NUMERIC);

        $allowed = null;
        if ($operatorId > 0 && class_exists('BookingCatalog', false)) {
            $day = $dayStart > 0 ? $dayStart : (int)strtotime('today');
            $allowed = [];
            foreach (BookingCatalog::listServicesForOperator($operatorId, $day, $keepProductIds, 2000) as $svc) {
                $sid = (int)($svc['id'] ?? 0);
                if ($sid > 0) {
                    $allowed[$sid] = $svc;
                }
            }
        }

        $out = [];
        foreach ($counts as $gid => $uses) {
            $gid = (int)$gid;
            if ($allowed !== null && !isset($allowed[$gid])) {
                continue;
            }
            $name = '#' . $gid;
            $price = 0.0;
            $timeSec = 0;
            $typeId = 0;
            if ($allowed !== null && isset($allowed[$gid])) {
                $svc = $allowed[$gid];
                $name = (string)($svc['name'] ?? $name);
                $price = (float)($svc['price'] ?? $svc['price3'] ?? 0);
                $timeSec = max(0, (int)($svc['time_sec'] ?? 0));
                $typeId = max(0, (int)($svc['type_id'] ?? 0));
            } elseif (class_exists('ProductsRepository', false)) {
                $p = ProductsRepository::find($gid);
                if ($p) {
                    $name = (string)($p['name'] ?? $name);
                    $price = (float)($p['price'] ?? 0);
                    $timeSec = max(0, (int)($p['time_sec'] ?? 0));
                    $typeId = max(0, (int)($p['type_id'] ?? 0));
                }
            }
            $out[] = [
                'id' => $gid,
                'name' => $name,
                'price' => $price,
                'uses' => (int)$uses,
                'time_sec' => $timeSec,
                'type_id' => $typeId,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Basket duration in seconds (catalog time_sec × qty; chill via BookingSlots when available).
     *
     * @param list<array{id_good?:int,qty?:int}> $items
     */
    public static function durationSec(array $items): int
    {
        $products = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $gid = (int)($it['id_good'] ?? 0);
            $qty = max(1, (int)($it['qty'] ?? 1));
            if ($gid < 1) {
                continue;
            }
            $timeSec = 0;
            $timeNet = 0;
            $typeId = 0;
            if (class_exists('ProductsRepository', false)) {
                $p = ProductsRepository::find($gid);
                if ($p) {
                    $timeSec = max(0, (int)($p['time_sec'] ?? 0));
                    $timeNet = max(0, (int)($p['time_net'] ?? 0));
                    $typeId = max(0, (int)($p['type_id'] ?? 0));
                }
            }
            if ($timeSec < 1) {
                $timeSec = class_exists('BookingSlots', false)
                    ? BookingSlots::DEFAULT_DURATION_SEC
                    : 1800;
            }
            if ($timeNet < 1) {
                $timeNet = $timeSec;
            }
            for ($i = 0; $i < $qty; $i++) {
                $products[] = [
                    'id' => $gid,
                    'time_sec' => $timeSec,
                    'time_net' => $timeNet,
                    'type_id' => $typeId,
                ];
            }
        }
        if ($products === []) {
            return 0;
        }
        if (class_exists('BookingSlots', false)) {
            $probe = BookingSlots::check(
                $products,
                1,
                [],
                (int)mktime(10, 0, 0),
                (int)mktime(23, 59, 59)
            );
            return max(BookingSlots::INTERVAL_SEC, (int)$probe['totalTime']);
        }
        $sum = 0;
        foreach ($products as $p) {
            $sum += (int)$p['time_sec'];
        }
        return max(300, $sum);
    }

    /**
     * Build items list from event edit POST (content[] + content_price[id] + content_qty[id]).
     *
     * @param array<string,mixed> $post
     * @return list<array{id_good:int,price:float,qty:int,pos:int}>
     */
    public static function itemsFromPost(array $post): array
    {
        $rawContent = $post['content'] ?? [];
        if (!is_array($rawContent)) {
            return [];
        }
        $rawPrices = is_array($post['content_price'] ?? null) ? $post['content_price'] : [];
        $rawQtys = is_array($post['content_qty'] ?? null) ? $post['content_qty'] : [];

        $items = [];
        $pos = 0;
        $seen = [];
        foreach ($rawContent as $cid) {
            $cid = (int)$cid;
            if ($cid < 1 || isset($seen[$cid])) {
                continue;
            }
            $seen[$cid] = true;
            $prod = class_exists('ProductsRepository', false) ? ProductsRepository::find($cid) : null;
            $price = null;
            if (isset($rawPrices[$cid]) && $rawPrices[$cid] !== '') {
                $price = (float)$rawPrices[$cid];
            } elseif (isset($rawPrices[(string)$cid]) && $rawPrices[(string)$cid] !== '') {
                $price = (float)$rawPrices[(string)$cid];
            } else {
                $price = $prod ? (float)($prod['price'] ?? 0) : 0.0;
            }
            if ($price < 0) {
                $price = 0.0;
            }
            $qty = 1;
            if (isset($rawQtys[$cid]) && $rawQtys[$cid] !== '') {
                $qty = max(1, (int)$rawQtys[$cid]);
            } elseif (isset($rawQtys[(string)$cid]) && $rawQtys[(string)$cid] !== '') {
                $qty = max(1, (int)$rawQtys[(string)$cid]);
            }
            // Timed services (= procedures with duration): qty locked to 1 on events.
            $timeSec = $prod ? max(0, (int)($prod['time_sec'] ?? 0)) : 0;
            if ($timeSec > 0) {
                $qty = 1;
            }
            $items[] = [
                'id_good' => $cid,
                'price' => $price,
                'qty' => $qty,
                'pos' => $pos,
            ];
            $pos++;
        }
        return $items;
    }
}
