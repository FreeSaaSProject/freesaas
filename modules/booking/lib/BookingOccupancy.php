<?php
declare(strict_types=1);

/**
 * Builds usedTimes map for BookingSlots from events + procedures + advance orders.
 *
 * @phpstan-type UsedMap array<int,array<string,array{DTSTART:int,DTEND:int,ID_GOOD?:int}>>
 */
final class BookingOccupancy
{
    /**
     * @param int|null $excludeOrderId omit this advance order from occupancy (re-validate on edit)
     * @return UsedMap
     */
    public static function build(int $dayStart, int $dayEnd, ?int $excludeOrderId = null): array
    {
        if ($dayEnd <= $dayStart) {
            return [];
        }
        /** @var UsedMap $used */
        $used = [];
        $excludeOrderId = $excludeOrderId !== null && $excludeOrderId > 0 ? $excludeOrderId : null;

        if (class_exists(EventsRepository::class, false) && Database::tableExists('base_events')) {
            foreach (EventsRepository::listForDayRange($dayStart, $dayEnd) as $ev) {
                // Legacy blocks often have ENABLED=0 but still mean "busy".
                // Skip only non-block events that are explicitly disabled.
                $isBlock = !empty($ev['is_block']);
                if (!$isBlock && isset($ev['enabled']) && (int)$ev['enabled'] === 0) {
                    continue;
                }
                $op = (int)($ev['id_operator'] ?? 0);
                $start = (int)($ev['dtstart'] ?? 0);
                $end = (int)($ev['dtend'] ?? 0);
                if ($op < 1 || $start < 1 || $end <= $start) {
                    continue;
                }
                $gid = 0;
                $content = trim((string)($ev['content'] ?? ''));
                if ($content !== '') {
                    $parts = preg_split('/[,#\s]+/', $content) ?: [];
                    foreach ($parts as $part) {
                        $id = (int)$part;
                        if ($id > 0) {
                            $gid = $id;
                            break;
                        }
                    }
                }
                $key = 'e' . (int)($ev['id'] ?? 0);
                $used[$op][$key] = ['DTSTART' => $start, 'DTEND' => $end, 'ID_GOOD' => $gid];
            }
        }

        if (class_exists(ProceduresRepository::class, false) && Database::tableExists('base_procedures')) {
            foreach (ProceduresRepository::listForDayRange($dayStart, $dayEnd) as $pr) {
                if (isset($pr['enabled']) && (int)$pr['enabled'] === 0) {
                    continue;
                }
                $op = (int)($pr['id_operator'] ?? 0);
                if ($op < 1) {
                    $op = (int)($pr['id_admin'] ?? 0);
                }
                $start = (int)($pr['dtstart'] ?? 0);
                $end = (int)($pr['dtend'] ?? 0);
                if ($op < 1 || $start < 1 || $end <= $start) {
                    continue;
                }
                $key = 'p' . (int)($pr['id'] ?? 0);
                $used[$op][$key] = [
                    'DTSTART' => $start,
                    'DTEND' => $end,
                    'ID_GOOD' => (int)($pr['id_good'] ?? 0),
                ];
            }
        }

        if (class_exists(OrdersRepository::class, false) && Database::tableExists('base_orders')) {
            $newStatuses = OrdersRepository::NEW_STATUSES;
            foreach (OrdersRepository::listForDayRange($dayStart, $dayEnd) as $ord) {
                $oid = (int)($ord['id'] ?? 0);
                if ($excludeOrderId !== null && $oid === $excludeOrderId) {
                    continue;
                }
                $status = (string)($ord['status'] ?? '');
                if (!in_array($status, $newStatuses, true)) {
                    continue;
                }
                $op = (int)($ord['id_cosmetolog'] ?? 0);
                $start = (int)($ord['dt_event'] ?? 0);
                if ($op < 1 || $start < 1) {
                    continue;
                }
                $lines = OrdersRepository::decodeCart((string)($ord['cart'] ?? ''));
                $duration = self::cartDurationSec($lines);
                // Use real cart duration (not min 1h) so short procedures pack tightly.
                $end = $start + ($duration > 0 ? $duration : BookingSlots::DEFAULT_DURATION_SEC);
                $gid = $lines !== [] ? (int)($lines[0]['id'] ?? 0) : 0;
                $key = 'o' . (int)($ord['id'] ?? 0);
                $used[$op][$key] = ['DTSTART' => $start, 'DTEND' => $end, 'ID_GOOD' => $gid];
            }
        }

        return $used;
    }

    /**
     * @param list<array{id?:int}> $lines
     */
    private static function cartDurationSec(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $id = (int)($line['id'] ?? 0);
            if ($id < 1 || !class_exists(ProductsRepository::class, false)) {
                $total += BookingSlots::DEFAULT_DURATION_SEC;
                continue;
            }
            $p = ProductsRepository::find($id);
            $sec = $p ? max(0, (int)($p['time_sec'] ?? 0)) : 0;
            $total += $sec > 0 ? $sec : BookingSlots::DEFAULT_DURATION_SEC;
        }
        return $total;
    }
}
