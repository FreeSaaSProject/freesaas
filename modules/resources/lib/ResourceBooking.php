<?php
declare(strict_types=1);

/**
 * Slot-engine bridge: capacity checks + allocation sync for orders / events / procedures.
 */
final class ResourceBooking
{
    public static function ready(): bool
    {
        return class_exists(ResourcesRepository::class, false)
            && ResourcesRepository::tableReady()
            && ProductResourcesRepository::tableReady()
            && ResourceAllocationsRepository::tableReady();
    }

    /**
     * @param list<int> $productIds
     * @return list<array{resource_id:int,qty_needed:int,qty:int,name:string,enabled:int}>
     */
    public static function requirementsForProducts(array $productIds): array
    {
        if (!self::ready()) {
            return [];
        }
        return ProductResourcesRepository::requirementsForProducts($productIds);
    }

    /**
     * Build exclude map from booking occupancy opts.
     *
     * @param int|list<int>|null $excludeProcedureId
     * @return array{order?:int,event?:int,procedure?:int|list<int>}
     */
    public static function excludeFrom(
        ?int $excludeOrderId = null,
        $excludeProcedureId = null,
        ?int $excludeEventId = null
    ): array {
        $ex = [];
        if ($excludeOrderId !== null && $excludeOrderId > 0) {
            $ex['order'] = $excludeOrderId;
        }
        if ($excludeEventId !== null && $excludeEventId > 0) {
            $ex['event'] = $excludeEventId;
        }
        if ($excludeProcedureId !== null) {
            if (is_array($excludeProcedureId)) {
                $ids = array_values(array_filter(array_map('intval', $excludeProcedureId), static fn (int $id): bool => $id > 0));
                if ($ids !== []) {
                    $ex['procedure'] = $ids;
                }
            } elseif ((int)$excludeProcedureId > 0) {
                $ex['procedure'] = (int)$excludeProcedureId;
            }
        }
        return $ex;
    }

    /**
     * Drop slot starts that exceed resource capacity for [start, start+totalTime).
     *
     * @param array<int,int> $timeSlots
     * @param list<array{resource_id:int,qty_needed:int,qty:int,name:string}> $requirements
     * @param array{order?:int,event?:int,procedure?:int|list<int>}|null $exclude
     * @return array<int,int>
     */
    public static function filterSlots(array $timeSlots, int $totalTime, array $requirements, ?array $exclude = null): array
    {
        if ($timeSlots === [] || $requirements === [] || $totalTime < 1 || !self::ready()) {
            return $timeSlots;
        }
        $minStart = min(array_map('intval', array_keys($timeSlots)));
        $maxEnd = max(array_map('intval', array_keys($timeSlots))) + $totalTime;
        $allocs = ResourceAllocationsRepository::listOverlapping($minStart, $maxEnd, $exclude);
        foreach ($timeSlots as $timeSlot => $_) {
            $start = (int)$timeSlot;
            $end = $start + $totalTime;
            if (!self::windowFitsRequirements($requirements, $start, $end, $allocs)) {
                unset($timeSlots[$timeSlot]);
            }
        }
        return $timeSlots;
    }

    /**
     * @param list<int> $productIds
     * @param array{order?:int,event?:int,procedure?:int|list<int>}|null $exclude
     * @throws InvalidArgumentException
     */
    public static function assertWindow(array $productIds, int $dtStart, int $dtEnd, ?array $exclude = null): void
    {
        if (!self::ready() || $dtStart < 1 || $dtEnd <= $dtStart) {
            return;
        }
        $reqs = self::requirementsForProducts($productIds);
        if ($reqs === []) {
            return;
        }
        foreach ($reqs as $req) {
            if ((int)$req['qty_needed'] > (int)$req['qty']) {
                throw new InvalidArgumentException(t('resources.err.need_exceeds_qty', [
                    'name' => (string)$req['name'],
                    'need' => (int)$req['qty_needed'],
                    'qty' => (int)$req['qty'],
                ]));
            }
        }
        $allocs = ResourceAllocationsRepository::listOverlapping($dtStart, $dtEnd, $exclude);
        if (!self::windowFitsRequirements($reqs, $dtStart, $dtEnd, $allocs)) {
            $busy = self::firstBusyName($reqs, $dtStart, $dtEnd, $allocs);
            throw new InvalidArgumentException(t('resources.err.busy', [
                'name' => $busy !== '' ? $busy : t('resources.err.busy_generic'),
            ]));
        }
    }

    /**
     * Replace allocations for a source after a successful write.
     *
     * @param list<int> $productIds
     */
    public static function sync(string $source, int $sourceId, array $productIds, int $dtStart, int $dtEnd): void
    {
        if (!self::ready() || $sourceId < 1) {
            return;
        }
        ResourceAllocationsRepository::release($source, $sourceId);
        if ($dtStart < 1 || $dtEnd <= $dtStart) {
            return;
        }
        $reqs = self::requirementsForProducts($productIds);
        if ($reqs === []) {
            return;
        }
        $rows = [];
        foreach ($reqs as $req) {
            $rows[] = [
                'resource_id' => (int)$req['resource_id'],
                'qty_used' => max(1, (int)$req['qty_needed']),
            ];
        }
        ResourceAllocationsRepository::insertBatch($source, $sourceId, $dtStart, $dtEnd, $rows);
    }

    public static function release(string $source, int $sourceId): void
    {
        if (!self::ready()) {
            return;
        }
        ResourceAllocationsRepository::release($source, $sourceId);
    }

    /**
     * Day-wide lock when products need shared resources (serialize cross-staff capacity writes).
     * No-op (runs $fn immediately) when module off or products need nothing.
     *
     * @template T
     * @param list<int> $productIds
     * @param callable():T $fn
     * @return T|null null when lock not acquired
     */
    public static function withDayLock(int $dayTs, array $productIds, int $timeoutSec, callable $fn)
    {
        if (!self::ready() || self::requirementsForProducts($productIds) === []) {
            return $fn();
        }
        $ymd = date('Ymd', $dayTs > 0 ? $dayTs : time());
        $name = 'book_res_' . $ymd;
        if (strlen($name) > 64) {
            $name = substr($name, 0, 64);
        }
        $pdo = Database::pdo();
        $got = false;
        try {
            $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
            $st->execute([$name, max(0, $timeoutSec)]);
            $got = ((int)$st->fetchColumn() === 1);
            if (!$got) {
                return null;
            }
            return $fn();
        } finally {
            if ($got) {
                try {
                    $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $rel->execute([$name]);
                } catch (Throwable $e) {
                    // ignore unlock errors
                }
            }
        }
    }

    /**
     * Sync advance order when still in NEW_STATUSES; otherwise release.
     *
     * @param array<string,mixed> $order
     */
    public static function syncOrder(array $order): void
    {
        if (!self::ready()) {
            return;
        }
        $id = (int)($order['id'] ?? 0);
        if ($id < 1) {
            return;
        }
        $status = (string)($order['status'] ?? '');
        $newStatuses = class_exists(OrdersRepository::class, false)
            ? OrdersRepository::NEW_STATUSES
            : ['cashComplete'];
        if (!in_array($status, $newStatuses, true)) {
            self::release(ResourceAllocationsRepository::SOURCE_ORDER, $id);
            return;
        }
        $dtStart = (int)($order['dt_event'] ?? 0);
        if ($dtStart < 1) {
            self::release(ResourceAllocationsRepository::SOURCE_ORDER, $id);
            return;
        }
        $lines = [];
        if (class_exists(OrdersRepository::class, false)) {
            $lines = OrdersRepository::decodeCart((string)($order['cart'] ?? ''));
        }
        $productIds = [];
        foreach ($lines as $line) {
            $pid = (int)($line['id'] ?? 0);
            if ($pid > 0) {
                $productIds[] = $pid;
            }
        }
        $duration = self::cartDurationSec($productIds, $lines);
        self::sync(
            ResourceAllocationsRepository::SOURCE_ORDER,
            $id,
            $productIds,
            $dtStart,
            $dtStart + $duration
        );
    }

    /**
     * @param array<string,mixed> $event
     * @param list<array{id?:int,product_id?:int}>|null $items
     */
    public static function syncEvent(array $event, ?array $items = null): void
    {
        if (!self::ready()) {
            return;
        }
        $id = (int)($event['id'] ?? 0);
        if ($id < 1) {
            return;
        }
        if (!empty($event['is_block']) || (isset($event['enabled']) && (int)$event['enabled'] === 0)) {
            self::release(ResourceAllocationsRepository::SOURCE_EVENT, $id);
            return;
        }
        $dtStart = (int)($event['dtstart'] ?? 0);
        $dtEnd = (int)($event['dtend'] ?? 0);
        $productIds = self::productIdsFromEventItems($items ?? ($event['items'] ?? null), $event);
        if ($productIds === [] || $dtStart < 1 || $dtEnd <= $dtStart) {
            self::release(ResourceAllocationsRepository::SOURCE_EVENT, $id);
            return;
        }
        self::sync(ResourceAllocationsRepository::SOURCE_EVENT, $id, $productIds, $dtStart, $dtEnd);
    }

    /**
     * @param array<string,mixed> $procedure
     */
    public static function syncProcedure(array $procedure): void
    {
        if (!self::ready()) {
            return;
        }
        $id = (int)($procedure['id'] ?? 0);
        if ($id < 1) {
            return;
        }
        if (isset($procedure['enabled']) && (int)$procedure['enabled'] === 0) {
            self::release(ResourceAllocationsRepository::SOURCE_PROCEDURE, $id);
            return;
        }
        $productId = (int)($procedure['id_good'] ?? 0);
        $dtStart = (int)($procedure['dtstart'] ?? 0);
        $dtEnd = (int)($procedure['dtend'] ?? 0);
        if ($productId < 1 || $dtStart < 1 || $dtEnd <= $dtStart) {
            self::release(ResourceAllocationsRepository::SOURCE_PROCEDURE, $id);
            return;
        }
        self::sync(
            ResourceAllocationsRepository::SOURCE_PROCEDURE,
            $id,
            [$productId],
            $dtStart,
            $dtEnd
        );
    }

    /**
     * @param list<array{resource_id:int,qty_needed:int,qty:int,name:string}> $requirements
     * @param list<array{resource_id?:int,dt_start?:int,dt_end?:int,qty_used?:int}> $allocs
     */
    private static function windowFitsRequirements(array $requirements, int $dtStart, int $dtEnd, array $allocs): bool
    {
        foreach ($requirements as $req) {
            $rid = (int)$req['resource_id'];
            $need = max(1, (int)$req['qty_needed']);
            $cap = max(1, (int)$req['qty']);
            if ($need > $cap) {
                return false;
            }
            $used = 0;
            foreach ($allocs as $a) {
                if ((int)($a['resource_id'] ?? 0) !== $rid) {
                    continue;
                }
                $aStart = (int)($a['dt_start'] ?? 0);
                $aEnd = (int)($a['dt_end'] ?? 0);
                if ($aStart < 1 || $aEnd <= $aStart) {
                    continue;
                }
                if ($aStart < $dtEnd && $aEnd > $dtStart) {
                    $used += max(1, (int)($a['qty_used'] ?? 1));
                }
            }
            if (($used + $need) > $cap) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<array{resource_id:int,qty_needed:int,qty:int,name:string}> $requirements
     * @param list<array{resource_id?:int,dt_start?:int,dt_end?:int,qty_used?:int}> $allocs
     */
    private static function firstBusyName(array $requirements, int $dtStart, int $dtEnd, array $allocs): string
    {
        foreach ($requirements as $req) {
            $rid = (int)$req['resource_id'];
            $need = max(1, (int)$req['qty_needed']);
            $cap = max(1, (int)$req['qty']);
            $used = 0;
            foreach ($allocs as $a) {
                if ((int)($a['resource_id'] ?? 0) !== $rid) {
                    continue;
                }
                $aStart = (int)($a['dt_start'] ?? 0);
                $aEnd = (int)($a['dt_end'] ?? 0);
                if ($aStart < $dtEnd && $aEnd > $dtStart) {
                    $used += max(1, (int)($a['qty_used'] ?? 1));
                }
            }
            if (($used + $need) > $cap) {
                return (string)$req['name'];
            }
        }
        return '';
    }

    /**
     * @param mixed $items
     * @param array<string,mixed> $event
     * @return list<int>
     */
    private static function productIdsFromEventItems($items, array $event): array
    {
        $ids = [];
        if (is_array($items)) {
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $pid = (int)($it['id'] ?? $it['product_id'] ?? $it['id_good'] ?? 0);
                if ($pid > 0) {
                    $ids[] = $pid;
                }
            }
        }
        if ($ids === [] && isset($event['content_ids']) && is_array($event['content_ids'])) {
            foreach ($event['content_ids'] as $cid) {
                $pid = (int)$cid;
                if ($pid > 0) {
                    $ids[] = $pid;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $productIds
     * @param list<array{id?:int}> $lines
     */
    private static function cartDurationSec(array $productIds, array $lines): int
    {
        if (class_exists(BookingOccupancy::class, false) && class_exists(BookingSlots::class, false)) {
            // Mirror BookingOccupancy cart duration via probe when possible.
            $products = [];
            $ids = $productIds !== [] ? $productIds : [];
            if ($ids === []) {
                foreach ($lines as $line) {
                    $id = (int)($line['id'] ?? 0);
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
            }
            if (class_exists(BookingAvailability::class, false) && $ids !== []) {
                try {
                    $products = BookingAvailability::loadProducts($ids);
                } catch (Throwable $e) {
                    $products = [];
                }
            }
            if ($products !== []) {
                $probe = BookingSlots::check(
                    $products,
                    1,
                    [],
                    (int)mktime(10, 0, 0),
                    (int)mktime(23, 59, 59)
                );
                return max(BookingSlots::INTERVAL_SEC, (int)$probe['totalTime']);
            }
        }
        $total = 0;
        foreach ($productIds as $pid) {
            if ($pid < 1 || !class_exists(ProductsRepository::class, false)) {
                $total += 3600;
                continue;
            }
            $p = ProductsRepository::find($pid);
            $sec = $p ? max(0, (int)($p['time_sec'] ?? 0)) : 0;
            $total += $sec > 0 ? $sec : 3600;
        }
        return max(300, $total > 0 ? $total : 3600);
    }
}
