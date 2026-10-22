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
     * @param int|list<int>|null $excludeProcedureId omit procedure(s) from occupancy (procedure / chain edit)
     * @param int|null $excludeEventId omit this calendar event from occupancy (event edit re-check)
     * @return UsedMap
     */
    public static function build(
        int $dayStart,
        int $dayEnd,
        ?int $excludeOrderId = null,
        $excludeProcedureId = null,
        ?int $excludeEventId = null
    ): array {
        if ($dayEnd <= $dayStart) {
            return [];
        }
        /** @var UsedMap $used */
        $used = [];
        $excludeOrderId = $excludeOrderId !== null && $excludeOrderId > 0 ? $excludeOrderId : null;
        $excludeEventId = $excludeEventId !== null && $excludeEventId > 0 ? $excludeEventId : null;
        $excludeSet = self::normalizeExcludeProcedureIds($excludeProcedureId);

        if (class_exists(EventsRepository::class, false) && Database::tableExists('base_events')) {
            foreach (EventsRepository::listForDayRange($dayStart, $dayEnd) as $ev) {
                $eid = (int)($ev['id'] ?? 0);
                if ($excludeEventId !== null && $eid === $excludeEventId) {
                    continue;
                }
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
                $contentIds = is_array($ev['content_ids'] ?? null) ? $ev['content_ids'] : [];
                foreach ($contentIds as $part) {
                    $cid = (int)$part;
                    if ($cid > 0) {
                        $gid = $cid;
                        break;
                    }
                }
                $key = 'e' . $eid;
                $used[$op][$key] = ['DTSTART' => $start, 'DTEND' => $end, 'ID_GOOD' => $gid];
            }
        }

        if (class_exists(ProceduresRepository::class, false) && Database::tableExists('base_procedures')) {
            /** @var array<int,array{end:int,op:int}> $excludeParents */
            $excludeParents = [];
            foreach ($excludeSet as $exId => $_) {
                $exRow = ProceduresRepository::find($exId);
                if (!is_array($exRow)) {
                    continue;
                }
                $exOp = (int)($exRow['id_operator'] ?? 0);
                if ($exOp < 1) {
                    $exOp = (int)($exRow['id_admin'] ?? 0);
                }
                $excludeParents[$exId] = [
                    'end' => (int)($exRow['dtend'] ?? 0),
                    'op' => $exOp,
                ];
            }
            foreach (ProceduresRepository::listForDayRange($dayStart, $dayEnd) as $pr) {
                if (isset($pr['enabled']) && (int)$pr['enabled'] === 0) {
                    continue;
                }
                $pid = (int)($pr['id'] ?? 0);
                if ($pid > 0 && isset($excludeSet[$pid])) {
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
                if ($excludeParents !== []) {
                    $notice = (string)($pr['notice'] ?? '');
                    // Parse id as int — strncmp('chill#12', …) wrongly matches chill#123.
                    $chillOf = class_exists('ProceduresChain', false)
                        ? ProceduresChain::chillParentId($notice)
                        : ((strncmp($notice, 'chill#', 6) === 0) ? (int)substr($notice, 6) : 0);
                    $skip = false;
                    foreach ($excludeParents as $exId => $meta) {
                        if ($chillOf > 0 && $chillOf === (int)$exId) {
                            $skip = true;
                            break;
                        }
                        // Same operator + starts at parent DTEND: linked chill even without notice.
                        if (
                            $meta['end'] > 0
                            && $start === $meta['end']
                            && $op === $meta['op']
                            && class_exists(TerminalsConfig::class, false)
                        ) {
                            $chillGood = TerminalsConfig::chillProductId();
                            if ($chillGood > 0 && (int)($pr['id_good'] ?? 0) === $chillGood) {
                                $skip = true;
                                break;
                            }
                        }
                    }
                    if ($skip) {
                        continue;
                    }
                }
                $key = 'p' . $pid;
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
     * Cart span including capped chill (matches BookingSlots::check totalTime).
     *
     * @param list<array{id?:int}> $lines
     */
    private static function cartDurationSec(array $lines): int
    {
        $products = [];
        foreach ($lines as $line) {
            $id = (int)($line['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            if (!class_exists(ProductsRepository::class, false)) {
                $products[] = [
                    'id' => $id,
                    'time_sec' => BookingSlots::DEFAULT_DURATION_SEC,
                    'time_net' => BookingSlots::DEFAULT_DURATION_SEC,
                    'type_id' => 0,
                ];
                continue;
            }
            $p = ProductsRepository::find($id);
            $timeSec = $p ? max(0, (int)($p['time_sec'] ?? 0)) : 0;
            $timeNet = $p ? max(0, (int)($p['time_net'] ?? 0)) : 0;
            if ($timeSec < 1) {
                $timeSec = BookingSlots::DEFAULT_DURATION_SEC;
            }
            if ($timeNet < 1) {
                $timeNet = $timeSec;
            }
            $products[] = [
                'id' => $id,
                'time_sec' => $timeSec,
                'time_net' => $timeNet,
                'type_id' => $p ? max(0, (int)($p['type_id'] ?? 0)) : 0,
            ];
        }
        if ($products === []) {
            return BookingSlots::DEFAULT_DURATION_SEC;
        }
        $probe = BookingSlots::check(
            $products,
            1,
            [],
            (int)mktime(10, 0, 0),
            (int)mktime(23, 59, 59)
        );
        return max(BookingSlots::INTERVAL_SEC, (int)$probe['totalTime']);
    }

    /**
     * @param int|list<int>|null $excludeProcedureId
     * @return array<int,true>
     */
    public static function normalizeExcludeProcedureIds($excludeProcedureId): array
    {
        if ($excludeProcedureId === null) {
            return [];
        }
        $ids = is_array($excludeProcedureId) ? $excludeProcedureId : [$excludeProcedureId];
        $out = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $out[$id] = true;
            }
        }
        return $out;
    }
}
